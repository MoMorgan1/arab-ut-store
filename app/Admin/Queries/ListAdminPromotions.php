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
 *     status?: ?string,
 *     sort?: string,
 *     direction?: string,
 *     per_page?: int,
 *     page?: int
 * }
 * @phpstan-type AdminPromotionComponentRow array{
 *     id: string,
 *     productId: string,
 *     productName: string,
 *     quantity: int
 * }
 * @phpstan-type AdminPromotionRow array{
 *     id: string,
 *     nameAr: string,
 *     nameEn: string,
 *     badgeAr: string|null,
 *     badgeEn: string|null,
 *     mechanic: string,
 *     scope: string,
 *     categoryName: string|null,
 *     categoryId: string|null,
 *     serviceType: string|null,
 *     discountType: string,
 *     value: int,
 *     buyQuantity: int|null,
 *     getQuantity: int|null,
 *     maxApplications: int|null,
 *     discountTarget: string|null,
 *     qualifyingScope: string|null,
 *     bundlePriceHalalah: int|null,
 *     appliesToPromotedItems: bool,
 *     components: list<AdminPromotionComponentRow>,
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
     *     activeCount: int,
     *     scheduledCount: int,
     *     pausedCount: int,
     *     endedCount: int
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
                'promotions.mechanic',
                'promotions.scope',
                'categories.name_en as category_name',
                'categories.public_id as category_public_id',
                'promotions.service_type',
                'promotions.discount_type',
                'promotions.value',
                'promotions.buy_quantity',
                'promotions.get_quantity',
                'promotions.max_applications',
                'promotions.discount_target',
                'promotions.qualifying_scope',
                'promotions.bundle_price_halalah',
                'promotions.applies_to_promoted_items',
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

        $componentsByPromotion = $this->loadComponentsForRows($rows);

        $now = Carbon::now('UTC');

        return [
            'promotions' => $this->projectPromotions($rows, $componentsByPromotion),
            'pagination' => $this->pagination($paginator),
            'totalCount' => (int) DB::table('promotions')->count(),
            'activeCount' => (int) DB::table('promotions')
                ->where('is_active', true)
                ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                ->count(),
            'scheduledCount' => (int) DB::table('promotions')
                ->where('is_active', true)
                ->whereNotNull('starts_at')
                ->where('starts_at', '>', $now)
                ->count(),
            'pausedCount' => (int) DB::table('promotions')
                ->where('is_active', false)
                ->count(),
            'endedCount' => (int) DB::table('promotions')
                ->whereNotNull('ends_at')
                ->where('ends_at', '<', $now)
                ->count(),
        ];
    }

    /** @param AdminPromotionsFilters $filters */
    private function filteredQuery(array $filters): Builder
    {
        $query = DB::table('promotions');
        $now = Carbon::now('UTC');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('promotions.name_ar', 'LIKE', '%'.$search.'%')
                    ->orWhere('promotions.name_en', 'LIKE', '%'.$search.'%');
            });
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            match ($filters['status']) {
                'active' => $query->where('promotions.is_active', true)
                    ->where(fn (Builder $q) => $q->whereNull('promotions.starts_at')->orWhere('promotions.starts_at', '<=', $now))
                    ->where(fn (Builder $q) => $q->whereNull('promotions.ends_at')->orWhere('promotions.ends_at', '>=', $now)),
                'scheduled' => $query->where('promotions.is_active', true)
                    ->whereNotNull('promotions.starts_at')
                    ->where('promotions.starts_at', '>', $now),
                'paused' => $query->where('promotions.is_active', false),
                'ended' => $query->whereNotNull('promotions.ends_at')
                    ->where('promotions.ends_at', '<', $now),
                default => null,
            };
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
     * @return array<int, list<AdminPromotionComponentRow>>
     */
    private function loadComponentsForRows(array $rows): array
    {
        $promotionIds = array_map(fn (stdClass $row): int => (int) $row->id, $rows);
        if (empty($promotionIds)) {
            return [];
        }

        $componentRows = DB::table('promotion_components')
            ->join('products', 'products.id', '=', 'promotion_components.product_id')
            ->whereIn('promotion_components.promotion_id', $promotionIds)
            ->select([
                'promotion_components.promotion_id',
                'promotion_components.public_id',
                'promotion_components.quantity',
                'products.public_id as product_public_id',
                'products.name_en as product_name_en',
            ])
            ->orderBy('promotion_components.id')
            ->get();

        $byPromotion = [];
        foreach ($componentRows as $comp) {
            $pId = (int) $comp->promotion_id;
            if (! isset($byPromotion[$pId])) {
                $byPromotion[$pId] = [];
            }
            $byPromotion[$pId][] = [
                'id' => (string) $comp->public_id,
                'productId' => (string) $comp->product_public_id,
                'productName' => (string) $comp->product_name_en,
                'quantity' => (int) $comp->quantity,
            ];
        }

        return $byPromotion;
    }

    /**
     * @param  list<stdClass>  $rows
     * @param  array<int, list<AdminPromotionComponentRow>>  $componentsByPromotion
     * @return list<AdminPromotionRow>
     */
    private function projectPromotions(array $rows, array $componentsByPromotion): array
    {
        return array_map(function (stdClass $row) use ($componentsByPromotion): array {
            $rowId = (int) $row->id;

            return [
                'id' => (string) $row->public_id,
                'nameAr' => (string) $row->name_ar,
                'nameEn' => (string) $row->name_en,
                'badgeAr' => $row->badge_ar !== null ? (string) $row->badge_ar : null,
                'badgeEn' => $row->badge_en !== null ? (string) $row->badge_en : null,
                'mechanic' => $row->mechanic !== null ? (string) $row->mechanic : 'item',
                'scope' => (string) $row->scope,
                'categoryName' => $row->category_name !== null ? (string) $row->category_name : null,
                'categoryId' => $row->category_public_id !== null ? (string) $row->category_public_id : null,
                'serviceType' => $row->service_type !== null ? (string) $row->service_type : null,
                'discountType' => (string) $row->discount_type,
                'value' => (int) $row->value,
                'buyQuantity' => $row->buy_quantity !== null ? (int) $row->buy_quantity : null,
                'getQuantity' => $row->get_quantity !== null ? (int) $row->get_quantity : null,
                'maxApplications' => $row->max_applications !== null ? (int) $row->max_applications : null,
                'discountTarget' => $row->discount_target !== null ? (string) $row->discount_target : 'cheapest',
                'qualifyingScope' => $row->qualifying_scope !== null ? (string) $row->qualifying_scope : null,
                'bundlePriceHalalah' => $row->bundle_price_halalah !== null ? (int) $row->bundle_price_halalah : null,
                'appliesToPromotedItems' => (bool) ($row->applies_to_promoted_items ?? false),
                'components' => $componentsByPromotion[$rowId] ?? [],
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
