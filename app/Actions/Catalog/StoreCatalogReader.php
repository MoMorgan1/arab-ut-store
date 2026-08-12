<?php

namespace App\Actions\Catalog;

use App\Actions\Pricing\ConvertDisplayMoney;
use App\Enums\ServiceType;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Support\Money;
use App\ValueObjects\Pricing\PreparedDisplayMoneyConverter;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

final class StoreCatalogReader
{
    public function __construct(private readonly ConvertDisplayMoney $convertDisplayMoney) {}

    /**
     * @return array{
     *   service: string,
     *   products: list<array<string, mixed>>,
     *   filterCounts: array{all: int, players: int, icons: int, upgrades: int, foundations: int},
     *   query: array{filter: string, sort: string, q: string, page: int},
     *   pagination: array{page: int, perPage: int, total: int, lastPage: int}
     * }
     */
    public function category(
        ServiceType $service,
        string $locale,
        string $displayCurrency,
        string $filter,
        string $sort,
        string $search,
        int $page,
    ): array {
        $converter = $this->converter($displayCurrency);
        $products = $this->publicProducts($service)
            ->map(fn (Product $product): array => $this->present($product, $locale, $converter));

        $needle = mb_strtolower(trim($search));

        if ($needle !== '') {
            $products = $products->filter(function (array $product) use ($needle): bool {
                $haystack = mb_strtolower($product['name'].' '.$product['description']);

                return str_contains($haystack, $needle);
            });
        }

        $filterCounts = $this->filterCounts($products);

        if ($filter !== 'all') {
            $products = $products->filter(
                fn (array $product): bool => in_array($filter, $product['filters'], true),
            );
        }

        $products = match ($sort) {
            'newest' => $products->sortByDesc('createdAt')->sortByDesc('id'),
            'price_asc' => $products->sortBy([
                fn (array $left, array $right): int => ($left['price']['amountMinor'] ?? PHP_INT_MAX) <=> ($right['price']['amountMinor'] ?? PHP_INT_MAX),
                fn (array $left, array $right): int => $left['id'] <=> $right['id'],
            ]),
            'price_desc' => $products->sortBy([
                fn (array $left, array $right): int => ($right['price']['amountMinor'] ?? -1) <=> ($left['price']['amountMinor'] ?? -1),
                fn (array $left, array $right): int => $left['id'] <=> $right['id'],
            ]),
            default => $products->sortBy([
                ['sortOrder', 'asc'],
                ['id', 'asc'],
            ]),
        };
        $products = $products->values();
        $perPage = 12;
        $total = $products->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        return [
            'service' => $service->value,
            'products' => array_values($products->forPage($page, $perPage)
                ->map(fn (array $product): array => $this->withoutInternalFields($product))
                ->values()
                ->all()),
            'filterCounts' => $filterCounts,
            'query' => ['filter' => $filter, 'sort' => $sort, 'q' => $search, 'page' => $page],
            'pagination' => compact('page', 'perPage', 'total', 'lastPage'),
        ];
    }

    /** @return array{service: string, product: array<string, mixed>} */
    public function productBySlug(
        ServiceType $service,
        string $slug,
        string $locale,
        string $displayCurrency,
    ): array {
        $product = $this->publicProducts($service)->firstWhere('slug', $slug);

        abort_unless($product instanceof Product, 404);

        return [
            'service' => $service->value,
            'product' => $this->withoutInternalFields(
                $this->present($product, $locale, $this->converter($displayCurrency)),
            ),
        ];
    }

    /** @return array{service: string, product: array<string, mixed>} */
    public function featuredProduct(
        ServiceType $service,
        string $locale,
        string $displayCurrency,
    ): array {
        $product = $this->publicProducts($service)->first();

        abort_unless($product instanceof Product, 404);

        return [
            'service' => $service->value,
            'product' => $this->withoutInternalFields(
                $this->present($product, $locale, $this->converter($displayCurrency)),
            ),
        ];
    }

    /** @return Collection<int, Product> */
    private function publicProducts(ServiceType $service): Collection
    {
        return Product::query()
            ->where('service_type', $service)
            ->where('is_visible', true)
            ->whereNull('archived_at')
            ->whereHas('variants', fn ($query) => $query->where('is_active', true))
            ->where(function ($query): void {
                $query->whereNull('category_id')
                    ->orWhereHas('category', fn ($category) => $category->where('is_visible', true));
            })
            ->with([
                'media' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'variants' => fn ($query) => $query->where('is_active', true)->orderBy('id'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function present(
        Product $product,
        string $locale,
        ?PreparedDisplayMoneyConverter $converter,
    ): array {
        $variants = $product->variants;
        $lowest = $variants
            ->map(fn (ProductVariant $variant): int => $this->effectivePrice($variant))
            ->filter(fn (int $price): bool => $price > 0)
            ->min();
        $media = $product->media->first();

        return [
            'id' => $product->public_id,
            'slug' => $product->slug,
            'url' => $this->productUrl($product),
            'name' => $this->localized($product, 'name', $locale),
            'description' => $this->localized($product, 'description', $locale),
            'image' => $media instanceof ProductMedia ? [
                'url' => Storage::disk($media->disk)->url($media->path),
                'alt' => $this->localized($media, 'alt', $locale),
            ] : null,
            'price' => is_int($lowest) && $converter instanceof PreparedDisplayMoneyConverter
                ? $this->convert($converter, $lowest)
                : null,
            'platforms' => $variants
                ->map(fn (ProductVariant $variant): string => $variant->platform->value)
                ->unique()
                ->values()
                ->all(),
            'variants' => $variants->map(fn (ProductVariant $variant): array => [
                'id' => $variant->public_id,
                'name' => $this->localized($variant, 'name', $locale),
                'platform' => $variant->platform->value,
                'price' => $converter instanceof PreparedDisplayMoneyConverter
                    ? $this->convert($converter, $this->effectivePrice($variant))
                    : null,
            ])->values()->all(),
            'filters' => $this->filters($variants),
            'sortOrder' => (int) $product->sort_order,
            'createdAt' => $product->created_at?->getTimestamp() ?? 0,
        ];
    }

    /** @param Collection<int, ProductVariant> $variants
     * @return list<string>
     */
    private function filters(Collection $variants): array
    {
        return array_values($variants
            ->map(function (ProductVariant $variant): ?string {
                $configuration = $variant->getAttribute('configuration');
                $filter = is_array($configuration) ? ($configuration['sbcCategory'] ?? null) : null;

                if ($filter === 'challenges') {
                    return 'upgrades';
                }

                return in_array($filter, ['players', 'icons', 'upgrades', 'foundations'], true)
                    ? $filter
                    : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     * @return array{all: int, players: int, icons: int, upgrades: int, foundations: int}
     */
    private function filterCounts(Collection $products): array
    {
        $counts = [
            'all' => $products->count(),
            'players' => 0,
            'icons' => 0,
            'upgrades' => 0,
            'foundations' => 0,
        ];

        foreach ($products as $product) {
            foreach ($product['filters'] as $filter) {
                if (array_key_exists($filter, $counts)) {
                    $counts[$filter]++;
                }
            }
        }

        return $counts;
    }

    private function effectivePrice(ProductVariant $variant): int
    {
        $sale = $variant->getAttribute('sale_price_halalah');

        return is_int($sale) ? $sale : (int) $variant->getAttribute('price_halalah');
    }

    private function converter(string $displayCurrency): ?PreparedDisplayMoneyConverter
    {
        try {
            return $this->convertDisplayMoney->prepare($displayCurrency);
        } catch (DomainException) {
            return null;
        }
    }

    /** @return array{amountMinor: int, currency: string}|null */
    private function convert(PreparedDisplayMoneyConverter $converter, int $halalah): ?array
    {
        try {
            return $converter->convert(Money::fromHalalah($halalah));
        } catch (DomainException) {
            return null;
        }
    }

    private function localized(object $model, string $field, string $locale): string
    {
        $primary = trim((string) data_get($model, "{$field}_{$locale}"));

        if ($primary !== '') {
            return $primary;
        }

        $fallback = $locale === 'ar' ? 'en' : 'ar';

        return trim((string) data_get($model, "{$field}_{$fallback}"));
    }

    private function productUrl(Product $product): ?string
    {
        $service = $product->service_type;

        if (! in_array($service, [ServiceType::Sbc, ServiceType::Objectives], true)) {
            return null;
        }

        $locale = app()->getLocale();
        $localized = $locale === 'en';
        $name = ($localized ? 'localized.' : '')."store.{$service->value}.show";
        $parameters = $localized ? ['locale' => $locale, 'slug' => $product->slug] : ['slug' => $product->slug];

        return route($name, $parameters, absolute: false);
    }

    /** @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function withoutInternalFields(array $product): array
    {
        unset($product['filters'], $product['sortOrder'], $product['createdAt']);

        return $product;
    }
}
