<?php

namespace App\Admin\Queries;

use App\Enums\ServiceType;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type AdminProductsFilters array{
 *     search?: ?string,
 *     service_type?: ?string,
 *     authority?: ?string,
 *     source?: ?string,
 *     visibility?: ?string,
 *     archived?: ?string,
 *     sort?: string,
 *     direction?: string,
 *     per_page?: int,
 *     page?: int
 * }
 * @phpstan-type AdminProductRow array{
 *     id: string,
 *     slug: string,
 *     name: string,
 *     nameAr: string,
 *     nameEn: string,
 *     serviceType: string,
 *     authority: string,
 *     source: array{name: string, key: string}|null,
 *     isVisible: bool,
 *     sortOrder: int,
 *     isArchived: bool,
 *     variantsCount: int,
 *     createdAt: string,
 *     updatedAt: string
 * }
 */
final class ListAdminProducts
{
    /**
     * @param  AdminProductsFilters  $filters
     * @return array{
     *     products: list<AdminProductRow>,
     *     pagination: array{
     *         currentPage: int,
     *         lastPage: int,
     *         perPage: int,
     *         total: int,
     *         from: ?int,
     *         to: ?int
     *     }
     * }
     */
    public function paginate(array $filters, string $locale = 'en'): array
    {
        $query = $this->filteredQuery($filters, $locale);

        $variantsCount = DB::table('product_variants')
            ->selectRaw('count(*)')
            ->whereColumn('product_variants.product_id', 'products.id');

        $paginator = $query->select([
            'products.id',
            'products.public_id',
            'products.slug',
            'products.service_type',
            'products.authority',
            'products.name_ar',
            'products.name_en',
            'products.is_visible',
            'products.sort_order',
            'products.archived_at',
            'products.created_at',
            'products.updated_at',
            'catalog_sources.name as source_name',
            'catalog_sources.key as source_key',
        ])
            ->leftJoin('catalog_sources', 'catalog_sources.id', '=', 'products.source_id')
            ->selectSub($variantsCount, 'variants_count')
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 15),
                page: (int) ($filters['page'] ?? 1),
            );

        $productRows = array_values(array_map(
            fn (stdClass $product): stdClass => $product,
            $paginator->items(),
        ));

        return [
            'products' => $this->projectProducts($productRows, $locale),
            'pagination' => $this->pagination($paginator),
        ];
    }

    /** @param AdminProductsFilters $filters */
    private function filteredQuery(array $filters, string $locale): Builder
    {
        $query = DB::table('products');

        $this->applySearch($query, $filters['search'] ?? null);

        if (! empty($filters['service_type'])) {
            $query->where('products.service_type', $filters['service_type']);
        }

        if (! empty($filters['authority'])) {
            $query->where('products.authority', $filters['authority']);
        }

        if (! empty($filters['source'])) {
            if ($filters['source'] === 'manual') {
                $query->whereNull('products.source_id');
            } else {
                $query->whereExists(function (Builder $sub) use ($filters): void {
                    $sub->select(DB::raw(1))
                        ->from('catalog_sources')
                        ->whereColumn('catalog_sources.id', 'products.source_id')
                        ->where('catalog_sources.key', (string) $filters['source']);
                });
            }
        }

        if (! empty($filters['visibility'])) {
            if ($filters['visibility'] === 'visible') {
                $query->where('products.is_visible', true);
            } elseif ($filters['visibility'] === 'hidden') {
                $query->where('products.is_visible', false);
            }
        }

        if (($filters['archived'] ?? 'active') === 'archived') {
            $query->whereNotNull('products.archived_at');
        } else {
            $query->whereNull('products.archived_at');
        }

        $sortColumn = match ($filters['sort'] ?? 'created_at') {
            'name' => $locale === 'en' ? 'products.name_en' : 'products.name_ar',
            'updated_at' => 'products.updated_at',
            'sort_order' => 'products.sort_order',
            default => 'products.created_at',
        };
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortColumn, $direction)
            ->orderBy('products.id', $direction);
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        $lowercaseSearch = mb_strtolower($search);

        $query->where(function (Builder $productQuery) use ($search, $lowercaseSearch): void {
            $productQuery->where('products.public_id', $search)
                ->orWhere('products.slug', 'like', '%'.$search.'%')
                ->orWhereRaw('LOWER(products.name_en) LIKE ?', ['%'.$lowercaseSearch.'%'])
                ->orWhereRaw('products.name_ar LIKE ?', ['%'.$search.'%'])
                ->orWhereExists(function (Builder $sub) use ($search): void {
                    $sub->select(DB::raw(1))
                        ->from('product_variants')
                        ->whereColumn('product_variants.product_id', 'products.id')
                        ->where('product_variants.sku', 'like', '%'.$search.'%');
                });
        });
    }

    /**
     * @param  list<stdClass>  $products
     * @return list<AdminProductRow>
     */
    private function projectProducts(array $products, string $locale): array
    {
        return array_map(function (stdClass $product) use ($locale): array {
            return [
                'id' => (string) $product->public_id,
                'slug' => (string) $product->slug,
                'name' => $locale === 'en'
                    ? (string) $product->name_en
                    : (string) $product->name_ar,
                'nameAr' => (string) $product->name_ar,
                'nameEn' => (string) $product->name_en,
                'serviceType' => (string) $product->service_type,
                'authority' => (string) $product->authority,
                'source' => $product->source_name !== null
                    ? [
                        'name' => (string) $product->source_name,
                        'key' => (string) $product->source_key,
                    ]
                    : null,
                'isVisible' => (bool) $product->is_visible,
                'sortOrder' => (int) $product->sort_order,
                'isArchived' => $product->archived_at !== null,
                'variantsCount' => (int) ($product->variants_count ?? 0),
                'createdAt' => $product->created_at !== null
                    ? Carbon::parse($product->created_at, 'UTC')->utc()->toIso8601String()
                    : '',
                'updatedAt' => $product->updated_at !== null
                    ? Carbon::parse($product->updated_at, 'UTC')->utc()->toIso8601String()
                    : '',
            ];
        }, $products);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{currentPage: int, lastPage: int, perPage: int, total: int, from: ?int, to: ?int}
     */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
