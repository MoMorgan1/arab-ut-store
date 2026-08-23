<?php

namespace App\Admin\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type AdminCouponsFilters array{
 *     search?: ?string,
 *     sort?: string,
 *     direction?: string,
 *     per_page?: int,
 *     page?: int
 * }
 * @phpstan-type AdminCouponRow array{
 *     id: string,
 *     code: string,
 *     discountType: string,
 *     value: int,
 *     minimumOrderHalalah: int,
 *     maximumDiscountHalalah: int|null,
 *     usageLimit: int|null,
 *     perUserLimit: int|null,
 *     usedCount: int,
 *     startsAt: string|null,
 *     endsAt: string|null,
 *     isActive: bool,
 *     createdAt: string
 * }
 */
final class ListAdminCoupons
{
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
     *     activeCount: int
     * }
     */
    public function paginate(array $filters): array
    {
        $query = $this->filteredQuery($filters);

        $usedCount = DB::table('coupon_redemptions')
            ->selectRaw('count(*)')
            ->whereColumn('coupon_redemptions.coupon_id', 'coupons.id');

        $paginator = $query->select([
            'coupons.id',
            'coupons.public_id',
            'coupons.code',
            'coupons.discount_type',
            'coupons.value',
            'coupons.minimum_order_halalah',
            'coupons.maximum_discount_halalah',
            'coupons.usage_limit',
            'coupons.per_user_limit',
            'coupons.starts_at',
            'coupons.ends_at',
            'coupons.is_active',
            'coupons.created_at',
        ])
            ->selectSub($usedCount, 'used_count')
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 15),
                page: (int) ($filters['page'] ?? 1),
            );

        $rows = array_values(array_map(
            fn (stdClass $row): stdClass => $row,
            $paginator->items(),
        ));

        $totalCount = DB::table('coupons')->count();
        $activeCount = DB::table('coupons')->where('is_active', true)->count();

        return [
            'coupons' => $this->projectCoupons($rows),
            'pagination' => $this->pagination($paginator),
            'totalCount' => (int) $totalCount,
            'activeCount' => (int) $activeCount,
        ];
    }

    /** @param AdminCouponsFilters $filters */
    private function filteredQuery(array $filters): Builder
    {
        $query = DB::table('coupons');

        if (! empty($filters['search'])) {
            $search = mb_strtoupper(trim((string) $filters['search']));
            $query->where('coupons.code', 'LIKE', '%'.$search.'%');
        }

        $sortColumn = match ($filters['sort'] ?? 'created_at') {
            'code' => 'coupons.code',
            'used_count' => 'used_count',
            default => 'coupons.created_at',
        };
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortColumn, $direction)
            ->orderBy('coupons.id', $direction);
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<AdminCouponRow>
     */
    private function projectCoupons(array $rows): array
    {
        return array_map(function (stdClass $row): array {
            return [
                'id' => (string) $row->public_id,
                'code' => (string) $row->code,
                'discountType' => (string) $row->discount_type,
                'value' => (int) $row->value,
                'minimumOrderHalalah' => (int) $row->minimum_order_halalah,
                'maximumDiscountHalalah' => $row->maximum_discount_halalah !== null
                    ? (int) $row->maximum_discount_halalah
                    : null,
                'usageLimit' => $row->usage_limit !== null ? (int) $row->usage_limit : null,
                'perUserLimit' => $row->per_user_limit !== null ? (int) $row->per_user_limit : null,
                'usedCount' => (int) ($row->used_count ?? 0),
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
