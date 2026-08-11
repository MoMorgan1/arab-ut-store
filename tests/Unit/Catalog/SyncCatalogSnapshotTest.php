<?php

use App\Actions\Catalog\SyncCatalogSnapshot;
use App\Enums\Platform;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Exceptions\CatalogSnapshotReplay;
use App\Models\CatalogSource;
use App\Models\Category;
use App\Models\IntegrationEvent;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return array<string, mixed> */
function catalogUnitSnapshot(array $productChanges = []): array
{
    $product = array_replace_recursive([
        'externalId' => 'fresh-product',
        'categoryExternalId' => 'fresh-category',
        'slug' => 'fresh-product',
        'serviceType' => 'sbc',
        'name' => ['ar' => 'منتج جديد', 'en' => 'Fresh product'],
        'description' => ['ar' => 'وصف', 'en' => 'Description'],
        'sortOrder' => 10,
        'visible' => true,
        'variants' => [[
            'externalId' => 'fresh-variant',
            'sku' => 'FRESH_VARIANT',
            'platform' => 'playstation',
            'market' => 'console',
            'currency' => 'SAR',
            'name' => ['ar' => 'بلايستيشن', 'en' => 'PlayStation'],
            'priceMinor' => 10_000,
            'salePriceMinor' => null,
            'priceVersion' => 1,
            'active' => true,
            'configuration' => [],
        ]],
        'media' => [],
    ], $productChanges);

    return [
        'schemaVersion' => 1,
        'eventId' => (string) Str::ulid(),
        'runId' => (string) Str::ulid(),
        'generatedAt' => now()->utc()->toISOString(),
        'completeSnapshot' => true,
        'categories' => [[
            'externalId' => 'fresh-category',
            'slug' => 'fresh-category',
            'name' => ['ar' => 'فئة جديدة', 'en' => 'Fresh category'],
            'description' => ['ar' => null, 'en' => null],
            'sortOrder' => 10,
            'visible' => true,
        ]],
        'products' => [$product],
    ];
}

function n8nCatalogSource(): CatalogSource
{
    return CatalogSource::factory()->create([
        'key' => 'n8n-products',
        'authority' => ProductAuthority::Automation,
    ]);
}

it('archives missing automation rows while preserving unrelated manual catalog rows', function () {
    $source = n8nCatalogSource();
    $oldCategory = Category::factory()->create([
        'source_id' => $source->id,
        'external_id' => 'old-category',
        'slug' => 'old-category',
    ]);
    $oldProduct = Product::factory()->create([
        'source_id' => $source->id,
        'external_id' => 'old-product',
        'category_id' => $oldCategory->id,
        'slug' => 'old-product',
        'authority' => ProductAuthority::Automation,
    ]);
    $oldVariant = ProductVariant::factory()->create([
        'source_id' => $source->id,
        'external_id' => 'old-variant',
        'product_id' => $oldProduct->id,
        'sku' => 'OLD_VARIANT',
        'authority' => ProductAuthority::Automation,
    ]);
    $manual = Product::factory()->create(['slug' => 'manual-product']);

    $summary = app(SyncCatalogSnapshot::class)->execute(
        catalogUnitSnapshot(),
        str_repeat('a', 64),
    );

    expect($summary['archived'])->toBe(1)
        ->and($oldProduct->fresh()->archived_at)->not->toBeNull()
        ->and($oldProduct->fresh()->is_visible)->toBeFalse()
        ->and($oldVariant->fresh()->is_active)->toBeFalse()
        ->and($oldCategory->fresh()->is_visible)->toBeFalse()
        ->and($manual->fresh()->archived_at)->toBeNull()
        ->and($manual->fresh()->is_visible)->toBeTrue()
        ->and(Product::query()->where('external_id', 'fresh-product')->whereNull('archived_at')->exists())->toBeTrue();
});

it('deactivates an omitted automation variant on a retained product', function () {
    $source = n8nCatalogSource();
    $category = Category::factory()->create([
        'source_id' => $source->id,
        'external_id' => 'fresh-category',
        'slug' => 'fresh-category',
    ]);
    $product = Product::factory()->create([
        'source_id' => $source->id,
        'external_id' => 'fresh-product',
        'category_id' => $category->id,
        'slug' => 'fresh-product',
        'authority' => ProductAuthority::Automation,
    ]);
    $omitted = ProductVariant::factory()->create([
        'source_id' => $source->id,
        'external_id' => 'omitted-variant',
        'product_id' => $product->id,
        'sku' => 'OMITTED_VARIANT',
        'authority' => ProductAuthority::Automation,
        'is_active' => true,
    ]);

    app(SyncCatalogSnapshot::class)->execute(catalogUnitSnapshot(), str_repeat('b', 64));

    expect($omitted->fresh()->is_active)->toBeFalse();
});

it('rolls back the complete snapshot when one catalog row cannot be applied', function () {
    n8nCatalogSource();
    $manual = Product::factory()->create(['slug' => 'manual-product']);
    ProductVariant::factory()->create([
        'product_id' => $manual->id,
        'sku' => 'COLLIDING_SKU',
        'platform' => Platform::PlayStation,
        'service_type' => ServiceType::Sbc,
    ]);
    $before = [
        'categories' => Category::count(),
        'products' => Product::count(),
        'variants' => ProductVariant::count(),
        'events' => IntegrationEvent::count(),
    ];

    expect(fn () => app(SyncCatalogSnapshot::class)->execute(
        catalogUnitSnapshot(['variants' => [[
            'externalId' => 'colliding-variant',
            'sku' => 'COLLIDING_SKU',
            'platform' => 'playstation',
            'market' => 'console',
            'currency' => 'SAR',
            'name' => ['ar' => null, 'en' => null],
            'priceMinor' => 10_000,
            'salePriceMinor' => null,
            'priceVersion' => 1,
            'active' => true,
            'configuration' => [],
        ]]]),
        str_repeat('c', 64),
    ))->toThrow(QueryException::class);

    expect([
        'categories' => Category::count(),
        'products' => Product::count(),
        'variants' => ProductVariant::count(),
        'events' => IntegrationEvent::count(),
    ])->toBe($before);
});

it('mirrors allowlisted image media to a content-hash public path', function () {
    Storage::fake('public');
    config()->set('services.n8n.catalog_media_hosts', ['media.example.test']);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    Http::fake([
        'https://media.example.test/icon.png' => Http::response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($png),
        ]),
    ]);
    $snapshot = catalogUnitSnapshot(['media' => [[
        'url' => 'https://media.example.test/icon.png',
        'alt' => ['ar' => 'أيقونة الخدمة', 'en' => 'Service icon'],
        'sortOrder' => 1,
    ]]]);

    app(SyncCatalogSnapshot::class)->execute($snapshot, str_repeat('d', 64));

    $media = ProductMedia::sole();

    expect($media->path)->toBe('catalog/'.hash('sha256', $png).'.png')
        ->and($media->alt_ar)->toBe('أيقونة الخدمة')
        ->and($media->alt_en)->toBe('Service icon');
    Storage::disk('public')->assertExists($media->path);
});

it('preserves last-good product media when a remote image is unsafe or unavailable', function (string $url) {
    Storage::fake('public');
    config()->set('services.n8n.catalog_media_hosts', ['media.example.test']);
    Http::fake([
        'https://media.example.test/missing.png' => Http::response('', 503),
    ]);
    $source = n8nCatalogSource();
    $category = Category::factory()->create([
        'source_id' => $source->id,
        'external_id' => 'fresh-category',
        'slug' => 'fresh-category',
    ]);
    $product = Product::factory()->create([
        'source_id' => $source->id,
        'external_id' => 'fresh-product',
        'category_id' => $category->id,
        'slug' => 'fresh-product',
        'authority' => ProductAuthority::Automation,
    ]);
    ProductMedia::create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => 'catalog/last-good.png',
        'alt_ar' => 'الصورة السابقة',
        'alt_en' => 'Previous image',
        'sort_order' => 0,
    ]);
    $snapshot = catalogUnitSnapshot(['media' => [[
        'url' => $url,
        'alt' => ['ar' => 'جديدة', 'en' => 'New'],
        'sortOrder' => 1,
    ]]]);

    app(SyncCatalogSnapshot::class)->execute($snapshot, str_repeat('e', 64));

    expect(ProductMedia::query()->where('product_id', $product->id)->pluck('path')->all())
        ->toBe(['catalog/last-good.png']);
})->with([
    'unapproved host' => 'https://unapproved.example.test/image.png',
    'failed download' => 'https://media.example.test/missing.png',
]);

it('follows only allowlisted media redirects before mirroring the image', function () {
    Storage::fake('public');
    config()->set('services.n8n.catalog_media_hosts', ['media.example.test', 'cdn.example.test']);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    Http::fake([
        'https://media.example.test/image.png' => Http::response('', 302, [
            'Location' => 'https://cdn.example.test/final.png',
        ]),
        'https://cdn.example.test/final.png' => Http::response($png, 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);
    $snapshot = catalogUnitSnapshot(['media' => [[
        'url' => 'https://media.example.test/image.png',
        'alt' => ['ar' => null, 'en' => null],
        'sortOrder' => 0,
    ]]]);

    app(SyncCatalogSnapshot::class)->execute($snapshot, str_repeat('f', 64));

    expect(ProductMedia::count())->toBe(1);
    Http::assertSentCount(2);
});

it('does not request the target of an unapproved media redirect', function () {
    Storage::fake('public');
    config()->set('services.n8n.catalog_media_hosts', ['media.example.test']);
    Http::fake([
        'https://media.example.test/image.png' => Http::response('', 302, [
            'Location' => 'https://private.example.test/final.png',
        ]),
        'https://private.example.test/final.png' => Http::response('unsafe', 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);
    $snapshot = catalogUnitSnapshot(['media' => [[
        'url' => 'https://media.example.test/image.png',
        'alt' => ['ar' => null, 'en' => null],
        'sortOrder' => 0,
    ]]]);

    app(SyncCatalogSnapshot::class)->execute($snapshot, str_repeat('1', 64));

    expect(ProductMedia::count())->toBe(0);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://media.example.test/image.png');
    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://private.example.test/final.png');
});

it('rejects a replay before issuing another media request', function () {
    Storage::fake('public');
    config()->set('services.n8n.catalog_media_hosts', ['media.example.test']);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    Http::fake([
        'https://media.example.test/image.png' => Http::response($png, 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);
    $snapshot = catalogUnitSnapshot(['media' => [[
        'url' => 'https://media.example.test/image.png',
        'alt' => ['ar' => null, 'en' => null],
        'sortOrder' => 0,
    ]]]);
    $sync = app(SyncCatalogSnapshot::class);

    $sync->execute($snapshot, str_repeat('2', 64));

    expect(fn () => $sync->execute($snapshot, str_repeat('2', 64)))
        ->toThrow(CatalogSnapshotReplay::class);
    Http::assertSentCount(1);
});
