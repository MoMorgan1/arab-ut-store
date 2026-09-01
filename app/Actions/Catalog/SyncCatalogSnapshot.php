<?php

namespace App\Actions\Catalog;

use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Exceptions\CatalogSnapshotReplay;
use App\Models\CatalogSource;
use App\Models\CatalogSyncRun;
use App\Models\Category;
use App\Models\IntegrationEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\ValueObjects\Pricing\SbcCompletionPricing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class SyncCatalogSnapshot
{
    public function __construct(private MirrorCatalogMedia $mirrorCatalogMedia) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{runId:string,status:string,applied:int,archived:int}
     */
    public function execute(
        array $snapshot,
        string $signatureHash,
        string $sourceKey = 'n8n-products',
        string $sourceName = 'n8n Products',
    ): array {
        if ($this->eventExists($snapshot)) {
            throw new CatalogSnapshotReplay;
        }

        $preparedMedia = $this->mirrorCatalogMedia->prepare($snapshot['products']);

        return DB::transaction(fn (): array => $this->synchronize(
            $snapshot,
            $signatureHash,
            $preparedMedia,
            $sourceKey,
            $sourceName,
        ));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, list<array{path:string,alt_ar:?string,alt_en:?string,sort_order:int}>|null>  $preparedMedia
     * @return array{runId:string,status:string,applied:int,archived:int}
     */
    private function synchronize(
        array $snapshot,
        string $signatureHash,
        array $preparedMedia,
        string $sourceKey,
        string $sourceName,
    ): array {
        $source = $this->source($sourceKey, $sourceName);
        $this->claimEvent($source, $snapshot, $signatureHash);
        $run = $this->startRun($source, $snapshot);
        $categories = $this->categories($source, $snapshot['categories']);
        $applied = $this->products($source, $run, $categories, $snapshot['products'], $preparedMedia);
        $archived = $this->archiveMissing($source, $snapshot);
        $run->update(['status' => 'completed', 'applied_count' => $applied, 'completed_at' => now()]);

        return [
            'runId' => (string) $snapshot['runId'],
            'status' => 'completed',
            'applied' => $applied,
            'archived' => $archived,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function claimEvent(CatalogSource $source, array $snapshot, string $signatureHash): void
    {
        try {
            IntegrationEvent::create([
                'event_id' => $snapshot['eventId'],
                'event_type' => 'catalog.snapshot',
                'aggregate_type' => 'catalog',
                'aggregate_id' => $source->key,
                'schema_version' => $snapshot['schemaVersion'],
                'payload' => [
                    'run_id' => $snapshot['runId'],
                    'generated_at' => $snapshot['generatedAt'],
                    'source_key' => $source->key,
                    'product_count' => count($snapshot['products']),
                ],
                'signature_hash' => $signatureHash,
                'status' => 'processed',
                'idempotency_key' => 'catalog-snapshot:'.$snapshot['runId'],
                'attempts' => 1,
                'processed_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($this->eventExists($snapshot)) {
                throw new CatalogSnapshotReplay(previous: $exception);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     *
     * @phpstan-impure
     */
    private function eventExists(array $snapshot): bool
    {
        return IntegrationEvent::query()
            ->where('event_id', $snapshot['eventId'])
            ->orWhere('idempotency_key', 'catalog-snapshot:'.$snapshot['runId'])
            ->exists();
    }

    private function source(string $key, string $name): CatalogSource
    {
        return CatalogSource::firstOrCreate(
            ['key' => $key],
            ['name' => $name, 'authority' => ProductAuthority::Automation, 'is_enabled' => true],
        );
    }

    /** @param array<string, mixed> $snapshot */
    private function startRun(CatalogSource $source, array $snapshot): CatalogSyncRun
    {
        return $source->syncRuns()->create([
            'run_id' => $snapshot['runId'],
            'status' => 'running',
            'is_complete_snapshot' => true,
            'source_count' => count($snapshot['products']),
            'started_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $categoryPayloads
     * @return array<string, Category>
     */
    private function categories(CatalogSource $source, array $categoryPayloads): array
    {
        $categories = [];

        foreach ($categoryPayloads as $categoryPayload) {
            $category = Category::updateOrCreate(
                ['source_id' => $source->id, 'external_id' => $categoryPayload['externalId']],
                $this->categoryAttributes($categoryPayload),
            );
            $categories[$categoryPayload['externalId']] = $category;
        }

        return $categories;
    }

    /**
     * @param  array<string, mixed>  $categoryPayload
     * @return array{slug:string,name_ar:string,name_en:string,description_ar:?string,description_en:?string,sort_order:int,is_visible:bool}
     */
    private function categoryAttributes(array $categoryPayload): array
    {
        return [
            'slug' => (string) $categoryPayload['slug'],
            'name_ar' => (string) $categoryPayload['name']['ar'],
            'name_en' => (string) $categoryPayload['name']['en'],
            'description_ar' => is_string($categoryPayload['description']['ar'])
                ? $categoryPayload['description']['ar']
                : null,
            'description_en' => is_string($categoryPayload['description']['en'])
                ? $categoryPayload['description']['en']
                : null,
            'sort_order' => (int) $categoryPayload['sortOrder'],
            'is_visible' => (bool) $categoryPayload['visible'],
        ];
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  list<array<string, mixed>>  $productPayloads
     * @param  array<string, list<array{path:string,alt_ar:?string,alt_en:?string,sort_order:int}>|null>  $preparedMedia
     */
    private function products(
        CatalogSource $source,
        CatalogSyncRun $run,
        array $categories,
        array $productPayloads,
        array $preparedMedia,
    ): int {
        foreach ($productPayloads as $productPayload) {
            $product = $this->product($source, $categories, $productPayload);
            $this->variants($source, $product, $productPayload['variants']);
            $this->mirrorCatalogMedia->apply($product, $preparedMedia[$productPayload['externalId']]);
            $run->items()->create([
                'product_id' => $product->id,
                'external_id' => $productPayload['externalId'],
                'outcome' => 'applied',
            ]);
        }

        return count($productPayloads);
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, mixed>  $productPayload
     */
    private function product(CatalogSource $source, array $categories, array $productPayload): Product
    {
        $categoryExternalId = $productPayload['categoryExternalId'];

        return Product::updateOrCreate(
            ['source_id' => $source->id, 'external_id' => $productPayload['externalId']],
            [
                'category_id' => $categoryExternalId === null ? null : $categories[$categoryExternalId]->id,
                'slug' => $productPayload['slug'],
                'service_type' => ServiceType::from($productPayload['serviceType']),
                'authority' => ProductAuthority::Automation,
                'name_ar' => $productPayload['name']['ar'],
                'name_en' => $productPayload['name']['en'],
                'description_ar' => $productPayload['description']['ar'],
                'description_en' => $productPayload['description']['en'],
                'sort_order' => $productPayload['sortOrder'],
                'is_visible' => $productPayload['visible'],
                'archived_at' => null,
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $variantPayloads
     */
    private function variants(CatalogSource $source, Product $product, array $variantPayloads): void
    {
        foreach ($variantPayloads as $variantPayload) {
            $variant = ProductVariant::firstOrNew([
                'source_id' => $source->id,
                'external_id' => $variantPayload['externalId'],
            ]);
            $priceMinor = (int) $variantPayload['priceMinor'];
            $salePriceMinor = $variantPayload['salePriceMinor'] === null
                ? null
                : (int) $variantPayload['salePriceMinor'];
            $completionPricingChanged = $variant->exists
                && $product->service_type === ServiceType::Sbc
                && $this->completionPricingFingerprint(
                    is_array($variant->configuration) ? $variant->configuration : [],
                    $variant->sale_price_halalah ?? (int) $variant->price_halalah,
                ) !== $this->completionPricingFingerprint(
                    $variantPayload['configuration'],
                    $salePriceMinor ?? $priceMinor,
                );
            $priceChanged = $variant->exists && (
                (int) $variant->price_halalah !== $priceMinor
                || $variant->sale_price_halalah !== $salePriceMinor
                || $completionPricingChanged
            );
            $priceVersion = $variant->exists
                ? ((int) $variant->price_version) + ($priceChanged ? 1 : 0)
                : 1;

            $variant->fill([
                'product_id' => $product->id,
                'sku' => $variantPayload['sku'],
                'service_type' => $product->service_type,
                'platform' => $variantPayload['platform'],
                'authority' => ProductAuthority::Automation,
                'name_ar' => $variantPayload['name']['ar'],
                'name_en' => $variantPayload['name']['en'],
                'price_halalah' => $priceMinor,
                'sale_price_halalah' => $salePriceMinor,
                'price_version' => $priceVersion,
                'configuration' => $variantPayload['configuration'],
                'sbc_category' => is_array($variantPayload['configuration'])
                    ? ($variantPayload['configuration']['sbcCategory'] ?? null)
                    : null,
                'is_active' => $variantPayload['active'],
            ])->save();
        }
    }

    /** @param array<string, mixed> $configuration */
    private function completionPricingFingerprint(array $configuration, int $fallbackMinor): string
    {
        return SbcCompletionPricing::fromConfiguration(
            $configuration,
            $fallbackMinor,
            requireDeclared: false,
        )->fingerprint();
    }

    /** @param array<string, mixed> $snapshot */
    private function archiveMissing(CatalogSource $source, array $snapshot): int
    {
        $categoryExternalIds = array_column($snapshot['categories'], 'externalId');
        $productExternalIds = array_column($snapshot['products'], 'externalId');
        $variantExternalIds = [];

        foreach ($snapshot['products'] as $product) {
            foreach ($product['variants'] as $variant) {
                $variantExternalIds[] = $variant['externalId'];
            }
        }

        $source->categories()
            ->whereNotIn('external_id', $categoryExternalIds)
            ->update(['is_visible' => false]);

        $archived = $source->products()
            ->whereNull('archived_at')
            ->whereNotIn('external_id', $productExternalIds)
            ->update(['is_visible' => false, 'archived_at' => now()]);

        ProductVariant::query()
            ->where('source_id', $source->id)
            ->whereNotIn('external_id', $variantExternalIds)
            ->update(['is_active' => false]);

        return $archived;
    }
}
