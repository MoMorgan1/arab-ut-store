<?php

namespace App\Admin\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type AdminPromotionsFilters array{
 *     search?: ?string,
 *     sort?: string,
 *     direction?: string,
 *     per_page?: int,
 *     page?: int
 * }
 * @phpstan-type AdminPromotionRow array{
 *     id: string,
 *     nameAr: string,
 *     nameEn: string,
 *     badgeAr: string|null,
 *     badgeEn: string|null,
 *     scope: string,
 *     categoryName: string|null,
 *     categoryId: string|null,
 *     serviceType: string|null,
 *     discountType: string,
 *     value: int,
 *     startsAt: string|null,
 *     endsAt: string|null,
 *     isActive: bool,
 *     createdAt: string
 * }
 */
final class ListAdminPromotions
{
    /**
     * @param  AdminPromotionsFilters  $filters
     * @return array{
     *     promotions: list<AdminPromotionRow>,
     *     pagination: array{
     *         currentPage: int,
     *         lastPage: int,
     *         perPage: int,
     *         total: int,
     *         from: ?int,
     *         to: ?int
     *     },
     *     totalCount: int,
     *     activeCount: int
     * }
     */
    public function paginate(array $filters): array
    {
        $paginator = $this->filteredQuery($filters)
            ->select([
                'promotions.id',
                'promotions.public_id',
                'promotions.name_ar',
                'promotions.name_en',
                'promotions.badge_ar',
                'promotions.badge_en',
                'promotions.scope',
                'categories.name_en as category_name',
                'categories.public_id as category_public_id',
                'promotions.service_type',
                'promotions.discount_type',
                'promotions.value',
                'promotions.starts_at',
                'promotions.ends_at',
                'promotions.is_active',
                'promotions.created_at',
            ])
            ->leftJoin('categories', 'categories.id', '=', 'promotions.category_id')
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 15),
                page: (int) ($filters['page'] ?? 1),
            );

        $rows = array_values(array_map(
            fn (stdClass $row): stdClass => $row,
            $paginator->items(),
        ));

        return [
            'promotions' => $this->projectPromotions($rows),
            'pagination' => $this->pagination($paginator),
            'totalCount' => (int) DB::table('promotions')->count(),
            'activeCount' => (int) DB::table('promotions')->where('is_active', true)->count(),
        ];
    }

    /** @param AdminPromotionsFilters $filters */
    private function filteredQuery(array $filters): Builder
    {
        $query = DB::table('promotions');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('promotions.name_ar', 'LIKE', '%'.$search.'%')
                    ->orWhere('promotions.name_en', 'LIKE', '%'.$search.'%');
            });
        }

        $sortColumn = match ($filters['sort'] ?? 'created_at') {
            'name' => 'promotions.name_en',
            'value' => 'promotions.value',
            default => 'promotions.created_at',
        };
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortColumn, $direction)
            ->orderBy('promotions.id', $direction);
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<AdminPromotionRow>
     */
    private function projectPromotions(array $rows): array
    {
        return array_map(function (stdClass $row): array {
            return [
                'id' => (string) $row->public_id,
                'nameAr' => (string) $row->name_ar,
                'nameEn' => (string) $row->name_en,
                'badgeAr' => $row->badge_ar !== null ? (string) $row->badge_ar : null,
                'badgeEn' => $row->badge_en !== null ? (string) $row->badge_en : null,
                'scope' => (string) $row->scope,
                'categoryName' => $row->category_name !== null ? (string) $row->category_name : null,
                'categoryId' => $row->category_public_id !== null ? (string) $row->category_public_id : null,
                'serviceType' => $row->service_type !== null ? (string) $row->service_type : null,
                'discountType' => (string) $row->discount_type,
                'value' => (int) $row->value,
                'startsAt' => $row->starts_at !== null
                    ? Carbon::parse($row->starts_at, 'UTC')->utc()->toIso8601String()
                    : null,
                'endsAt' => $row->ends_at !== null
                    ? Carbon::parse($row->ends_at, 'UTC')->utc()->toIso8601String()
                    : null,
                'isActive' => (bool) $row->is_active,
                'createdAt' => $row->created_at !== null
                    ? Carbon::parse($row->created_at, 'UTC')->utc()->toIso8601String()
                    : '',
            ];
        }, $rows);
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
