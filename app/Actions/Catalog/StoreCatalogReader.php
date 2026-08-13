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
use Illuminate\Database\Eloquent\Builder;
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
        $query = $this->publicProductsQuery($service);
        $this->applySearch($query, $locale, $search);
        $filterCounts = $this->filterCounts($query);

        if ($filter !== 'all') {
            $this->applyFilter($query, $filter);
        }

        $perPage = 12;
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $this->applySort($query, $sort);
        $products = $this->withCatalogRelations($query)
            ->forPage($page, $perPage)
            ->get();

        return [
            'service' => $service->value,
            'products' => array_values($products
                ->map(fn (Product $product): array => $this->withoutInternalFields(
                    $this->present($product, $locale, $converter),
                ))
                ->values()
                ->all()),
            'filterCounts' => $filterCounts,
            'query' => ['filter' => $filter, 'sort' => $sort, 'q' => $search, 'page' => $page],
            'pagination' => compact('page', 'perPage', 'total', 'lastPage'),
        ];
    }

    /** @return array{service: string, product: array<string, mixed>, suggestions: list<array<string, mixed>>} */
    public function productBySlug(
        ServiceType $service,
        string $slug,
        string $locale,
        string $displayCurrency,
    ): array {
        $converter = $this->converter($displayCurrency);
        $product = $this->withCatalogRelations(
            $this->publicProductsQuery($service)->where('slug', $slug),
        )->first();

        abort_unless($product instanceof Product, 404);

        $suggestions = $this->withCatalogRelations(
            $this->publicProductsQuery($service)->whereKeyNot($product->getKey()),
        )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(8)
            ->get();

        return [
            'service' => $service->value,
            'product' => $this->withoutInternalFields(
                $this->present($product, $locale, $converter),
            ),
            'suggestions' => array_values($suggestions
                ->map(fn (Product $suggestion): array => $this->withoutInternalFields(
                    $this->present($suggestion, $locale, $converter),
                ))
                ->all()),
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
        return $this->withCatalogRelations($this->publicProductsQuery($service))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** @return Builder<Product> */
    private function publicProductsQuery(ServiceType $service): Builder
    {
        return Product::query()
            ->where('service_type', $service)
            ->where('is_visible', true)
            ->whereNull('archived_at')
            ->whereHas('variants', fn ($query) => $query->where('is_active', true))
            ->where(function ($query): void {
                $query->whereNull('category_id')
                    ->orWhereHas('category', fn ($category) => $category->where('is_visible', true));
            });
    }

    /** @param Builder<Product> $query
     * @return Builder<Product>
     */
    private function withCatalogRelations(Builder $query): Builder
    {
        return $query->with([
            'media' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'variants' => fn ($query) => $query->where('is_active', true)->orderBy('id'),
        ]);
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

    /** @param Builder<Product> $query */
    private function applySearch(Builder $query, string $locale, string $search): void
    {
        $needle = trim($search);

        if ($needle === '') {
            return;
        }

        $fallback = $locale === 'ar' ? 'en' : 'ar';
        $columns = ["name_{$locale}", "description_{$locale}", "name_{$fallback}", "description_{$fallback}"];

        $query->where(function (Builder $searchQuery) use ($columns, $needle): void {
            foreach ($columns as $column) {
                $searchQuery->orWhereLike($column, "%{$needle}%", caseSensitive: false);
            }
        });
    }

    /** @param Builder<Product> $query */
    private function applyFilter(Builder $query, string $filter): void
    {
        $categories = $filter === 'upgrades' ? ['upgrades', 'challenges'] : [$filter];

        $query->whereHas('variants', function (Builder $variants) use ($categories): void {
            $variants->where('is_active', true)
                ->where(function (Builder $categoryQuery) use ($categories): void {
                    foreach ($categories as $category) {
                        $categoryQuery->orWhere('configuration->sbcCategory', $category);
                    }
                });
        });
    }

    /** @param Builder<Product> $query */
    private function applySort(Builder $query, string $sort): void
    {
        if (in_array($sort, ['price_asc', 'price_desc'], true)) {
            $this->applyPriceSort($query, $sort);

            return;
        }

        if ($sort === 'newest') {
            $query->orderByDesc('created_at')->orderByDesc('id');

            return;
        }

        $query->orderBy('sort_order')->orderBy('id');
    }

    /** @param Builder<Product> $query */
    private function applyPriceSort(Builder $query, string $sort): void
    {
        $effectivePrice = ProductVariant::query()
            ->selectRaw('MIN(COALESCE(sale_price_halalah, price_halalah))')
            ->whereColumn('product_id', 'products.id')
            ->where('is_active', true)
            ->whereRaw('COALESCE(sale_price_halalah, price_halalah) > 0');

        $query->addSelect(['effective_price_halalah' => $effectivePrice])
            ->orderByRaw('CASE WHEN effective_price_halalah IS NULL THEN 1 ELSE 0 END')
            ->orderBy('effective_price_halalah', $sort === 'price_asc' ? 'asc' : 'desc')
            ->orderBy('id');
    }

    /**
     * @param  Builder<Product>  $query
     * @return array{all: int, players: int, icons: int, upgrades: int, foundations: int}
     */
    private function filterCounts(Builder $query): array
    {
        $counts = [
            'all' => (clone $query)->count(),
            'players' => 0,
            'icons' => 0,
            'upgrades' => 0,
            'foundations' => 0,
        ];

        foreach (['players', 'icons', 'upgrades', 'foundations'] as $filter) {
            $filtered = clone $query;
            $this->applyFilter($filtered, $filter);
            $counts[$filter] = $filtered->count();
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
