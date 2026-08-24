<?php

namespace App\Admin\Queries;

use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\CouponTarget;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type AdminCouponsFilters array{
 *     search?: ?string,
 *     status?: ?string,
 *     scope?: ?string,
 *     discount_type?: ?string,
 *     sort?: string,
 *     direction?: string,
 *     per_page?: int,
 *     page?: int
 * }
 * @phpstan-type AdminCouponTargetSummary array{
 *     id: string,
 *     targetType: string,
 *     targetId: int,
 *     name: string
 * }
 * @phpstan-type AdminCouponRow array{
 *     id: string,
 *     code: string,
 *     descriptionAr: string|null,
 *     descriptionEn: string|null,
 *     discountType: string,
 *     value: int,
 *     minimumOrderHalalah: int,
 *     maximumDiscountHalalah: int|null,
 *     usageLimit: int|null,
 *     perUserLimit: int|null,
 *     usedCount: int,
 *     scope: string,
 *     serviceType: string|null,
 *     firstOrderOnly: bool,
 *     excludesPromotedItems: bool,
 *     startsAt: string|null,
 *     endsAt: string|null,
 *     isActive: bool,
 *     status: string,
 *     targets: list<AdminCouponTargetSummary>,
 *     categoryIds: list<int>,
 *     productIds: list<int>,
 *     createdAt: string
 * }
 */
final class ListAdminCoupons
{
    /**
     * Derive the coupon lifecycle display status.
     *
     * Precedence: paused > expired > exhausted > scheduled > active.
     */
    public static function deriveStatus(
        bool $isActive,
        ?string $startsAt,
        ?string $endsAt,
        ?int $usageLimit,
        int $activeRedemptionsCount,
        ?CarbonInterface $now = null,
    ): string {
        $now ??= now();

        if (! $isActive) {
            return 'paused';
        }

        if ($endsAt !== null && Carbon::parse($endsAt, 'UTC')->utc()->isPast()) {
            return 'expired';
        }

        if ($usageLimit !== null && $activeRedemptionsCount >= $usageLimit) {
            return 'exhausted';
        }

        if ($startsAt !== null && Carbon::parse($startsAt, 'UTC')->utc()->isFuture()) {
            return 'scheduled';
        }

        return 'active';
    }

    /**
     * @param  AdminCouponsFilters  $filters
     * @return array{
     *     coupons: list<AdminCouponRow>,
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
     *     counts: array{
     *         total: int,
     *         active: int,
     *         scheduled: int,
     *         paused: int,
     *         expired: int,
     *         exhausted: int
     *     }
     * }
     */
    public function paginate(array $filters): array
    {
        $now = now();

        $activeRedemptionsSubquery = DB::table('coupon_redemptions')
            ->join('orders', 'coupon_redemptions.order_id', '=', 'orders.id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereColumn('coupon_redemptions.coupon_id', 'coupons.id')
            ->selectRaw('count(*)');

        $allCoupons = DB::table('coupons')
            ->select([
                'coupons.id',
                'coupons.is_active',
                'coupons.starts_at',
                'coupons.ends_at',
                'coupons.usage_limit',
            ])
            ->selectSub($activeRedemptionsSubquery, 'active_redemptions_count')
            ->get();

        $counts = [
            'total' => 0,
            'active' => 0,
            'scheduled' => 0,
            'paused' => 0,
            'expired' => 0,
            'exhausted' => 0,
        ];

        /** @var array<int, string> $couponStatusMap */
        $couponStatusMap = [];

        foreach ($allCoupons as $item) {
            $status = self::deriveStatus(
                (bool) $item->is_active,
                $item->starts_at !== null ? (string) $item->starts_at : null,
                $item->ends_at !== null ? (string) $item->ends_at : null,
                $item->usage_limit !== null ? (int) $item->usage_limit : null,
                (int) ($item->active_redemptions_count ?? 0),
                $now,
            );

            $counts['total']++;
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            $couponStatusMap[(int) $item->id] = $status;
        }

        $query = $this->filteredQuery($filters, $couponStatusMap);

        $paginator = $query->select([
            'coupons.id',
            'coupons.public_id',
            'coupons.code',
            'coupons.description_ar',
            'coupons.description_en',
            'coupons.discount_type',
            'coupons.value',
            'coupons.minimum_order_halalah',
            'coupons.maximum_discount_halalah',
            'coupons.usage_limit',
            'coupons.per_user_limit',
            'coupons.scope',
            'coupons.service_type',
            'coupons.first_order_only',
            'coupons.excludes_promoted_items',
            'coupons.starts_at',
            'coupons.ends_at',
            'coupons.is_active',
            'coupons.created_at',
        ])
            ->selectSub($activeRedemptionsSubquery, 'used_count')
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 15),
                page: (int) ($filters['page'] ?? 1),
            );

        $rows = array_values(array_map(
            fn (stdClass $row): stdClass => $row,
            $paginator->items(),
        ));

        return [
            'coupons' => $this->projectCoupons($rows, $couponStatusMap),
            'pagination' => $this->pagination($paginator),
            'totalCount' => $counts['total'],
            'activeCount' => $counts['active'],
            'counts' => $counts,
        ];
    }

    /**
     * @param  AdminCouponsFilters  $filters
     * @param  array<int, string>  $couponStatusMap
     */
    private function filteredQuery(array $filters, array $couponStatusMap): Builder
    {
        $query = DB::table('coupons');

        if (! empty($filters['search'])) {
            $search = mb_strtoupper(trim((string) $filters['search']));
            $rawSearch = trim((string) $filters['search']);
            $query->where(function (Builder $inner) use ($search, $rawSearch): void {
                $inner->where('coupons.code', 'LIKE', '%'.$search.'%')
                    ->orWhere('coupons.description_ar', 'LIKE', '%'.$rawSearch.'%')
                    ->orWhere('coupons.description_en', 'LIKE', '%'.$rawSearch.'%');
            });
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $targetStatus = (string) $filters['status'];
            $matchingIds = array_keys(array_filter($couponStatusMap, fn (string $s): bool => $s === $targetStatus));
            if (empty($matchingIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('coupons.id', $matchingIds);
            }
        }

        if (! empty($filters['scope'])) {
            $query->where('coupons.scope', (string) $filters['scope']);
        }

        if (! empty($filters['discount_type'])) {
            $query->where('coupons.discount_type', (string) $filters['discount_type']);
        }

        $sortColumn = match ($filters['sort'] ?? 'created_at') {
            'code' => 'coupons.code',
            'used_count' => 'used_count',
            'value' => 'coupons.value',
            default => 'coupons.created_at',
        };
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortColumn, $direction)
            ->orderBy('coupons.id', $direction);
    }

    /**
     * @param  list<stdClass>  $rows
     * @param  array<int, string>  $couponStatusMap
     * @return list<AdminCouponRow>
     */
    private function projectCoupons(array $rows, array $couponStatusMap): array
    {
        if (empty($rows)) {
            return [];
        }

        $couponIds = array_map(fn (stdClass $row): int => (int) $row->id, $rows);

        $targets = DB::table('coupon_targets')
            ->whereIn('coupon_id', $couponIds)
            ->get();

        $categoryIds = [];
        $productIds = [];
        foreach ($targets as $target) {
            if ($target->target_type === CouponTarget::TYPE_CATEGORY) {
                $categoryIds[] = (int) $target->target_id;
            } elseif ($target->target_type === CouponTarget::TYPE_PRODUCT) {
                $productIds[] = (int) $target->target_id;
            }
        }

        $categoryNames = [];
        if (! empty($categoryIds)) {
            $categoryNames = DB::table('categories')
                ->whereIn('id', array_unique($categoryIds))
                ->pluck('name_en', 'id')
                ->all();
        }

        $productNames = [];
        if (! empty($productIds)) {
            $productNames = DB::table('products')
                ->whereIn('id', array_unique($productIds))
                ->pluck('name_en', 'id')
                ->all();
        }

        return array_map(function (stdClass $row) use ($targets, $categoryNames, $productNames, $couponStatusMap): array {
            $rowTargets = [];
            $rowCatIds = [];
            $rowProdIds = [];

            foreach ($targets->where('coupon_id', $row->id) as $target) {
                $targetId = (int) $target->target_id;
                $targetType = (string) $target->target_type;

                if ($targetType === CouponTarget::TYPE_CATEGORY) {
                    $rowCatIds[] = $targetId;
                    $name = (string) ($categoryNames[$targetId] ?? "Category #{$targetId}");
                } else {
                    $rowProdIds[] = $targetId;
                    $name = (string) ($productNames[$targetId] ?? "Product #{$targetId}");
                }

                $rowTargets[] = [
                    'id' => (string) $target->public_id,
                    'targetType' => $targetType,
                    'targetId' => $targetId,
                    'name' => $name,
                ];
            }

            $status = $couponStatusMap[(int) $row->id] ?? self::deriveStatus(
                (bool) $row->is_active,
                $row->starts_at !== null ? (string) $row->starts_at : null,
                $row->ends_at !== null ? (string) $row->ends_at : null,
                $row->usage_limit !== null ? (int) $row->usage_limit : null,
                (int) ($row->used_count ?? 0),
            );

            return [
                'id' => (string) $row->public_id,
                'code' => (string) $row->code,
                'descriptionAr' => $row->description_ar !== null ? (string) $row->description_ar : null,
                'descriptionEn' => $row->description_en !== null ? (string) $row->description_en : null,
                'discountType' => (string) $row->discount_type,
                'value' => (int) $row->value,
                'minimumOrderHalalah' => (int) $row->minimum_order_halalah,
                'maximumDiscountHalalah' => $row->maximum_discount_halalah !== null
                    ? (int) $row->maximum_discount_halalah
                    : null,
                'usageLimit' => $row->usage_limit !== null ? (int) $row->usage_limit : null,
                'perUserLimit' => $row->per_user_limit !== null ? (int) $row->per_user_limit : null,
                'usedCount' => (int) ($row->used_count ?? 0),
                'scope' => (string) ($row->scope ?? Coupon::SCOPE_ORDER),
                'serviceType' => $row->service_type !== null ? (string) $row->service_type : null,
                'firstOrderOnly' => (bool) ($row->first_order_only ?? false),
                'excludesPromotedItems' => (bool) ($row->excludes_promoted_items ?? false),
                'startsAt' => $row->starts_at !== null
                    ? Carbon::parse($row->starts_at, 'UTC')->utc()->toIso8601String()
                    : null,
                'endsAt' => $row->ends_at !== null
                    ? Carbon::parse($row->ends_at, 'UTC')->utc()->toIso8601String()
                    : null,
                'isActive' => (bool) $row->is_active,
                'status' => $status,
                'targets' => $rowTargets,
                'categoryIds' => array_values(array_unique($rowCatIds)),
                'productIds' => array_values(array_unique($rowProdIds)),
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
