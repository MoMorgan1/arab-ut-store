<?php

namespace App\Admin\Queries;

use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\CouponTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type AdminCouponPerformanceResult array{
 *     coupon: array{
 *         id: string,
 *         code: string,
 *         descriptionAr: string|null,
 *         descriptionEn: string|null,
 *         discountType: string,
 *         value: int,
 *         minimumOrderHalalah: int,
 *         maximumDiscountHalalah: int|null,
 *         usageLimit: int|null,
 *         perUserLimit: int|null,
 *         scope: string,
 *         serviceType: string|null,
 *         firstOrderOnly: bool,
 *         excludesPromotedItems: bool,
 *         startsAt: string|null,
 *         endsAt: string|null,
 *         isActive: bool,
 *         status: string,
 *         targets: list<array{id: string, targetType: string, targetId: int, name: string}>,
 *         categoryIds: list<int>,
 *         productIds: list<int>,
 *         createdAt: string
 *     },
 *     kpis: array{
 *         usedCount: int,
 *         usageLimit: int|null,
 *         uniqueCustomers: int,
 *         revenueAttributedHalalah: int,
 *         totalDiscountHalalah: int,
 *         totalRedemptions: int,
 *         releasedRedemptionsCount: int
 *     },
 *     rulesSummary: list<array{key: string, label: string, value: string, description?: string}>,
 *     chart: list<array{date: string, redemptions: int, revenueHalalah: int, discountHalalah: int}>,
 *     recentRedemptions: list<array{
 *         id: string,
 *         orderId: string,
 *         orderNumber: string,
 *         orderStatus: string,
 *         isPaid: bool,
 *         paidAt: string|null,
 *         orderTotalHalalah: int,
 *         discountHalalah: int,
 *         customer: array{id: string, name: string, email: string},
 *         redeemedAt: string
 *     }>
 * }
 */
final class ReadAdminCouponPerformance
{
    /**
     * @return AdminCouponPerformanceResult|null
     */
    public function findByPublicId(string $publicId, string $locale = 'en'): ?array
    {
        /** @var Coupon|null $coupon */
        $coupon = Coupon::query()
            ->where('public_id', $publicId)
            ->with('targets')
            ->first();

        if ($coupon === null) {
            return null;
        }

        $now = now();

        // 1. Total redemptions and active / released counts
        $allRedemptions = DB::table('coupon_redemptions')
            ->join('orders', 'coupon_redemptions.order_id', '=', 'orders.id')
            ->where('coupon_redemptions.coupon_id', $coupon->id)
            ->select([
                'coupon_redemptions.id',
                'orders.status',
                'orders.paid_at',
            ])
            ->get();

        $totalRedemptions = $allRedemptions->count();
        $activeRedemptionsCount = $allRedemptions->where('status', '!=', OrderStatus::Cancelled->value)->count();
        $releasedRedemptionsCount = $allRedemptions->where('status', '=', OrderStatus::Cancelled->value)->count();

        $derivedStatus = ListAdminCoupons::deriveStatus(
            (bool) $coupon->is_active,
            $coupon->starts_at?->toIso8601String(),
            $coupon->ends_at?->toIso8601String(),
            $coupon->usage_limit,
            $activeRedemptionsCount,
            $now,
        );

        // 2. Performance metrics for PAID orders only (orders.paid_at IS NOT NULL)
        $paidRedemptionsQuery = DB::table('coupon_redemptions')
            ->join('orders', 'coupon_redemptions.order_id', '=', 'orders.id')
            ->where('coupon_redemptions.coupon_id', $coupon->id)
            ->whereNotNull('orders.paid_at');

        $uniqueCustomers = (int) $paidRedemptionsQuery->distinct('coupon_redemptions.user_id')->count('coupon_redemptions.user_id');

        $revenueAttributedHalalah = (int) DB::table('coupon_redemptions')
            ->join('orders', 'coupon_redemptions.order_id', '=', 'orders.id')
            ->where('coupon_redemptions.coupon_id', $coupon->id)
            ->whereNotNull('orders.paid_at')
            ->sum('orders.total_halalah');

        $totalDiscountHalalah = (int) DB::table('order_discounts')
            ->join('orders', 'order_discounts.order_id', '=', 'orders.id')
            ->where('order_discounts.coupon_id', $coupon->id)
            ->whereNotNull('orders.paid_at')
            ->sum('order_discounts.amount_halalah');

        // 3. Targets and names
        $categoryIds = $coupon->targets
            ->where('target_type', CouponTarget::TYPE_CATEGORY)
            ->pluck('target_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $productIds = $coupon->targets
            ->where('target_type', CouponTarget::TYPE_PRODUCT)
            ->pluck('target_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

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

        $targetsSummary = [];
        foreach ($coupon->targets as $t) {
            $tId = (int) $t->target_id;
            $name = $t->target_type === CouponTarget::TYPE_CATEGORY
                ? ($categoryNames[$tId] ?? "Category #{$tId}")
                : ($productNames[$tId] ?? "Product #{$tId}");

            $targetsSummary[] = [
                'id' => (string) $t->public_id,
                'targetType' => (string) $t->target_type,
                'targetId' => $tId,
                'name' => (string) $name,
            ];
        }

        // 4. Redemptions per day for paid orders
        $dailyRows = DB::table('coupon_redemptions')
            ->join('orders', 'coupon_redemptions.order_id', '=', 'orders.id')
            ->leftJoin('order_discounts', function ($join) use ($coupon): void {
                $join->on('order_discounts.order_id', '=', 'orders.id')
                    ->where('order_discounts.coupon_id', '=', $coupon->id);
            })
            ->where('coupon_redemptions.coupon_id', $coupon->id)
            ->whereNotNull('orders.paid_at')
            ->select([
                DB::raw('date(orders.paid_at) as day_date'),
                DB::raw('count(coupon_redemptions.id) as daily_redemptions'),
                DB::raw('sum(orders.total_halalah) as daily_revenue'),
                DB::raw('sum(coalesce(order_discounts.amount_halalah, 0)) as daily_discount'),
            ])
            ->groupBy(DB::raw('date(orders.paid_at)'))
            ->orderBy('day_date', 'asc')
            ->get();

        $chart = [];
        foreach ($dailyRows as $dRow) {
            if ($dRow->day_date !== null) {
                $chart[] = [
                    'date' => (string) $dRow->day_date,
                    'redemptions' => (int) $dRow->daily_redemptions,
                    'revenueHalalah' => (int) ($dRow->daily_revenue ?? 0),
                    'discountHalalah' => (int) ($dRow->daily_discount ?? 0),
                ];
            }
        }

        // 5. Recent redemptions (last 20)
        $recentRows = DB::table('coupon_redemptions')
            ->join('orders', 'coupon_redemptions.order_id', '=', 'orders.id')
            ->join('users', 'coupon_redemptions.user_id', '=', 'users.id')
            ->leftJoin('order_discounts', function ($join) use ($coupon): void {
                $join->on('order_discounts.order_id', '=', 'orders.id')
                    ->where('order_discounts.coupon_id', '=', $coupon->id);
            })
            ->where('coupon_redemptions.coupon_id', $coupon->id)
            ->select([
                'coupon_redemptions.id',
                'coupon_redemptions.redeemed_at',
                'orders.public_id as order_public_id',
                'orders.order_number',
                'orders.status as order_status',
                'orders.paid_at',
                'orders.total_halalah',
                'order_discounts.amount_halalah as discount_halalah',
                'users.public_id as user_public_id',
                // users has no `name` column - it stores first_name / last_name.
                'users.first_name as user_first_name',
                'users.last_name as user_last_name',
                'users.email as user_email',
            ])
            ->orderBy('coupon_redemptions.id', 'desc')
            ->limit(20)
            ->get();

        $recentRedemptions = array_map(function (stdClass $r): array {
            return [
                'id' => (string) $r->id,
                'orderId' => (string) $r->order_public_id,
                'orderNumber' => (string) $r->order_number,
                'orderStatus' => (string) $r->order_status,
                'isPaid' => $r->paid_at !== null,
                'paidAt' => $r->paid_at !== null
                    ? Carbon::parse($r->paid_at, 'UTC')->utc()->toIso8601String()
                    : null,
                'orderTotalHalalah' => (int) $r->total_halalah,
                'discountHalalah' => (int) ($r->discount_halalah ?? 0),
                'customer' => [
                    'id' => (string) $r->user_public_id,
                    'name' => trim(((string) $r->user_first_name).' '.((string) $r->user_last_name)),
                    'email' => (string) $r->user_email,
                ],
                'redeemedAt' => Carbon::parse($r->redeemed_at, 'UTC')->utc()->toIso8601String(),
            ];
        }, $recentRows->all());

        // 6. Rules in force
        $rulesSummary = $this->buildRulesSummary($coupon, $targetsSummary, $releasedRedemptionsCount, $activeRedemptionsCount, $locale);

        return [
            'coupon' => [
                'id' => (string) $coupon->public_id,
                'code' => (string) $coupon->code,
                'descriptionAr' => $coupon->description_ar,
                'descriptionEn' => $coupon->description_en,
                'discountType' => (string) $coupon->discount_type,
                'value' => (int) $coupon->value,
                'minimumOrderHalalah' => (int) $coupon->minimum_order_halalah,
                'maximumDiscountHalalah' => $coupon->maximum_discount_halalah !== null ? (int) $coupon->maximum_discount_halalah : null,
                'usageLimit' => $coupon->usage_limit !== null ? (int) $coupon->usage_limit : null,
                'perUserLimit' => $coupon->per_user_limit !== null ? (int) $coupon->per_user_limit : null,
                'scope' => (string) ($coupon->scope ?? Coupon::SCOPE_ORDER),
                'serviceType' => $coupon->service_type,
                'firstOrderOnly' => (bool) $coupon->first_order_only,
                'excludesPromotedItems' => (bool) $coupon->excludes_promoted_items,
                'startsAt' => $coupon->starts_at?->toIso8601String(),
                'endsAt' => $coupon->ends_at?->toIso8601String(),
                'isActive' => (bool) $coupon->is_active,
                'status' => $derivedStatus,
                'targets' => $targetsSummary,
                'categoryIds' => array_values(array_unique($categoryIds)),
                'productIds' => array_values(array_unique($productIds)),
                'createdAt' => $coupon->created_at?->toIso8601String() ?? '',
            ],
            'kpis' => [
                'usedCount' => $activeRedemptionsCount,
                'usageLimit' => $coupon->usage_limit !== null ? (int) $coupon->usage_limit : null,
                'uniqueCustomers' => $uniqueCustomers,
                'revenueAttributedHalalah' => $revenueAttributedHalalah,
                'totalDiscountHalalah' => $totalDiscountHalalah,
                'totalRedemptions' => $totalRedemptions,
                'releasedRedemptionsCount' => $releasedRedemptionsCount,
            ],
            'rulesSummary' => $rulesSummary,
            'chart' => $chart,
            // array_values keeps this a list<> - array_map over a query result
            // preserves the source keys, which the declared return type forbids.
            'recentRedemptions' => array_values($recentRedemptions),
        ];
    }

    /**
     * @param  list<array{id: string, targetType: string, targetId: int, name: string}>  $targetsSummary
     * @return list<array{key: string, label: string, value: string, description?: string}>
     */
    private function buildRulesSummary(
        Coupon $coupon,
        array $targetsSummary,
        int $releasedCount,
        int $activeCount,
        string $locale,
    ): array {
        $rules = [];

        // Discount value
        if ($coupon->discount_type === 'percent') {
            $valText = "{$coupon->value}%";
            $capText = $coupon->maximum_discount_halalah !== null
                ? ' (Cap: '.number_format($coupon->maximum_discount_halalah / 100, 2).' SAR)'
                : '';
            $rules[] = [
                'key' => 'discount',
                'label' => 'Discount',
                'value' => $valText.$capText,
            ];
        } else {
            $rules[] = [
                'key' => 'discount',
                'label' => 'Discount',
                'value' => number_format($coupon->value / 100, 2).' SAR (Fixed)',
            ];
        }

        // Minimum order
        if ($coupon->minimum_order_halalah > 0) {
            $rules[] = [
                'key' => 'minimum_order',
                'label' => 'Minimum order',
                'value' => number_format($coupon->minimum_order_halalah / 100, 2).' SAR',
                'description' => 'Checked against eligible items only, not the whole cart.',
            ];
        } else {
            $rules[] = [
                'key' => 'minimum_order',
                'label' => 'Minimum order',
                'value' => 'No minimum',
            ];
        }

        // Scope
        $scope = $coupon->scope ?? Coupon::SCOPE_ORDER;
        if ($scope === Coupon::SCOPE_ORDER) {
            $rules[] = [
                'key' => 'scope',
                'label' => 'Scope',
                'value' => 'Entire order',
            ];
        } elseif ($scope === Coupon::SCOPE_CATEGORY) {
            $names = array_column($targetsSummary, 'name');
            $rules[] = [
                'key' => 'scope',
                'label' => 'Scope',
                'value' => 'Categories: '.(! empty($names) ? implode(', ', $names) : 'None selected'),
            ];
        } elseif ($scope === Coupon::SCOPE_PRODUCT) {
            $names = array_column($targetsSummary, 'name');
            $rules[] = [
                'key' => 'scope',
                'label' => 'Scope',
                'value' => 'Products: '.(! empty($names) ? implode(', ', $names) : 'None selected'),
            ];
        } elseif ($scope === Coupon::SCOPE_SERVICE) {
            $rules[] = [
                'key' => 'scope',
                'label' => 'Scope',
                'value' => 'Service: '.($coupon->service_type ?? 'Any service'),
            ];
        }

        // Eligibility
        $eligibilityFlags = [];
        if ($coupon->first_order_only) {
            $eligibilityFlags[] = 'First order only';
        }
        if ($coupon->excludes_promoted_items) {
            $eligibilityFlags[] = 'Excludes promoted items';
        }
        if (empty($eligibilityFlags)) {
            $eligibilityFlags[] = 'All customers';
        }
        $rules[] = [
            'key' => 'eligibility',
            'label' => 'Eligibility',
            'value' => implode(' • ', $eligibilityFlags),
        ];

        // Usage limits
        $limitText = $coupon->usage_limit !== null
            ? "{$activeCount} / {$coupon->usage_limit}"
            : "{$activeCount} / Unlimited";
        if ($releasedCount > 0) {
            $limitText .= " ({$releasedCount} released by cancellation)";
        }
        $rules[] = [
            'key' => 'usage_limit',
            'label' => 'Usage limit',
            'value' => $limitText,
            'description' => 'A cancelled order releases its redemption, so a failed payment does not burn a customer’s allowance.',
        ];

        if ($coupon->per_user_limit !== null) {
            $rules[] = [
                'key' => 'per_user_limit',
                'label' => 'Per-user limit',
                'value' => "{$coupon->per_user_limit} per customer",
            ];
        }

        // Validity window
        if ($coupon->starts_at === null && $coupon->ends_at === null) {
            $windowText = 'Always valid';
        } elseif ($coupon->ends_at === null) {
            $windowText = 'From '.$coupon->starts_at->format('Y-m-d H:i').' UTC';
        } elseif ($coupon->starts_at === null) {
            $windowText = 'Until '.$coupon->ends_at->format('Y-m-d H:i').' UTC';
        } else {
            $windowText = $coupon->starts_at->format('Y-m-d').' – '.$coupon->ends_at->format('Y-m-d').' UTC';
        }

        $rules[] = [
            'key' => 'validity',
            'label' => 'Validity window',
            'value' => $windowText,
        ];

        return $rules;
    }
}
