<?php

namespace App\Admin\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type AdminCategoriesFilters array{
 *     search?: ?string,
 *     visibility?: ?string,
 *     source?: ?string,
 *     sort?: string,
 *     direction?: string,
 *     per_page?: int,
 *     page?: int
 * }
 * @phpstan-type AdminCategoryRow array{
 *     id: string,
 *     slug: string,
 *     name: string,
 *     nameAr: string,
 *     nameEn: string,
 *     descriptionAr: ?string,
 *     descriptionEn: ?string,
 *     source: array{name: string, key: string}|null,
 *     isAutomation: bool,
 *     isVisible: bool,
 *     adminHidden: bool,
 *     adminHiddenAt: ?string,
 *     sortOrder: int,
 *     productsCount: int,
 *     visibleProductsCount: int,
 *     createdAt: string,
 *     updatedAt: string
 * }
 */
final class ListAdminCategories
{
    /**
     * @param  AdminCategoriesFilters  $filters
     * @return array{
     *     categories: list<AdminCategoryRow>,
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

        $productsCount = DB::table('products')
            ->selectRaw('count(*)')
            ->whereColumn('products.category_id', 'categories.id');

        $visibleProductsCount = DB::table('products')
            ->selectRaw('count(*)')
            ->whereColumn('products.category_id', 'categories.id')
            ->where('products.is_visible', true)
            ->whereNull('products.archived_at')
            ->whereNull('products.admin_hidden_at');

        $paginator = $query->select([
            'categories.id',
            'categories.public_id',
            'categories.slug',
            'categories.source_id',
            'categories.external_id',
            'categories.name_ar',
            'categories.name_en',
            'categories.description_ar',
            'categories.description_en',
            'categories.sort_order',
            'categories.is_visible',
            'categories.admin_hidden_at',
            'categories.created_at',
            'categories.updated_at',
            'catalog_sources.name as source_name',
            'catalog_sources.key as source_key',
        ])
            ->leftJoin('catalog_sources', 'catalog_sources.id', '=', 'categories.source_id')
            ->selectSub($productsCount, 'products_count')
            ->selectSub($visibleProductsCount, 'visible_products_count')
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 15),
                page: (int) ($filters['page'] ?? 1),
            );

        $categoryRows = array_values(array_map(
            fn (stdClass $category): stdClass => $category,
            $paginator->items(),
        ));

        return [
            'categories' => $this->projectCategories($categoryRows, $locale),
            'pagination' => $this->pagination($paginator),
        ];
    }

    /** @param AdminCategoriesFilters $filters */
    private function filteredQuery(array $filters, string $locale): Builder
    {
        $query = DB::table('categories');

        $this->applySearch($query, $filters['search'] ?? null);

        if (! empty($filters['visibility'])) {
            if ($filters['visibility'] === 'visible') {
                $query->where('categories.is_visible', true)->whereNull('categories.admin_hidden_at');
            } elseif ($filters['visibility'] === 'admin_hidden') {
                $query->whereNotNull('categories.admin_hidden_at');
            } elseif ($filters['visibility'] === 'automation_hidden') {
                $query->where('categories.is_visible', false);
            }
        }

        if (! empty($filters['source'])) {
            if ($filters['source'] === 'manual') {
                $query->whereNull('categories.source_id');
            } else {
                $query->whereExists(function (Builder $sub) use ($filters): void {
                    $sub->select(DB::raw(1))
                        ->from('catalog_sources')
                        ->whereColumn('catalog_sources.id', 'categories.source_id')
                        ->where('catalog_sources.key', (string) $filters['source']);
                });
            }
        }

        $nameColumn = $locale === 'en' ? 'categories.name_en' : 'categories.name_ar';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return match ($filters['sort'] ?? 'sort_order') {
            'name' => $query->orderBy($nameColumn, $direction)->orderBy('categories.sort_order', 'asc'),
            'updated_at' => $query->orderBy('categories.updated_at', $direction)->orderBy('categories.id', $direction),
            'created_at' => $query->orderBy('categories.created_at', $direction)->orderBy('categories.id', $direction),
            default => $query->orderBy('categories.sort_order', $direction)->orderBy($nameColumn, 'asc'),
        };
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        $lowercaseSearch = mb_strtolower($search);

        $query->where(function (Builder $categoryQuery) use ($search, $lowercaseSearch): void {
            $categoryQuery->where('categories.public_id', $search)
                ->orWhere('categories.slug', 'like', '%'.$search.'%')
                ->orWhereRaw('LOWER(categories.name_en) LIKE ?', ['%'.$lowercaseSearch.'%'])
                ->orWhereRaw('categories.name_ar LIKE ?', ['%'.$search.'%']);
        });
    }

    /**
     * @param  list<stdClass>  $categories
     * @return list<AdminCategoryRow>
     */
    private function projectCategories(array $categories, string $locale): array
    {
        return array_map(function (stdClass $category) use ($locale): array {
            return [
                'id' => (string) $category->public_id,
                'slug' => (string) $category->slug,
                'name' => $locale === 'en'
                    ? (string) $category->name_en
                    : (string) $category->name_ar,
                'nameAr' => (string) $category->name_ar,
                'nameEn' => (string) $category->name_en,
                'descriptionAr' => $category->description_ar !== null ? (string) $category->description_ar : null,
                'descriptionEn' => $category->description_en !== null ? (string) $category->description_en : null,
                'source' => $category->source_name !== null
                    ? [
                        'name' => (string) $category->source_name,
                        'key' => (string) $category->source_key,
                    ]
                    : null,
                'isAutomation' => $category->source_id !== null,
                'isVisible' => (bool) $category->is_visible,
                'adminHidden' => $category->admin_hidden_at !== null,
                'adminHiddenAt' => $category->admin_hidden_at !== null
                    ? Carbon::parse($category->admin_hidden_at, 'UTC')->utc()->toIso8601String()
                    : null,
                'sortOrder' => (int) $category->sort_order,
                'productsCount' => (int) ($category->products_count ?? 0),
                'visibleProductsCount' => (int) ($category->visible_products_count ?? 0),
                'createdAt' => $category->created_at !== null
                    ? Carbon::parse($category->created_at, 'UTC')->utc()->toIso8601String()
                    : '',
                'updatedAt' => $category->updated_at !== null
                    ? Carbon::parse($category->updated_at, 'UTC')->utc()->toIso8601String()
                    : '',
            ];
        }, $categories);
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
