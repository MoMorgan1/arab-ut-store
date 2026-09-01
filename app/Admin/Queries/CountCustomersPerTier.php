<?php

namespace App\Admin\Queries;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\LoyaltyTier;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class CountCustomersPerTier
{
    public const CACHE_KEY = 'admin:loyalty:customer_tier_counts';

    /**
     * Count customer accounts grouped by their reached loyalty tier using the
     * shared eligible-spend calculation in a single grouped SQL query.
     *
     * @return array<string, int>
     */
    public function execute(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function (): array {
            $tiers = LoyaltyTier::query()
                ->where('is_active', true)
                ->orderByDesc('minimum_lifetime_spend_halalah')
                ->orderByDesc('rank')
                ->get();

            if ($tiers->isEmpty()) {
                return [];
            }

            /** @var array<string, int> $counts */
            $counts = [];
            foreach ($tiers as $tier) {
                $counts[$tier->key] = 0;
            }

            $baseTier = $tiers->last(fn (LoyaltyTier $t): bool => $t->minimum_lifetime_spend_halalah === 0) ?? $tiers->last();
            $baseTierKey = $baseTier->key;

            $totalCustomers = User::query()
                ->where('role', UserRole::Customer)
                ->count();

            $settledStatuses = [
                PaymentStatus::Paid->value,
                PaymentStatus::PartiallyRefunded->value,
                PaymentStatus::Refunded->value,
            ];

            $orderSpends = DB::table('orders')
                ->join('users', 'users.id', '=', 'orders.user_id')
                ->leftJoinSub(
                    DB::table('payments')
                        ->select('order_id', DB::raw('SUM(captured_halalah) as captured_halalah'))
                        ->whereIn('status', $settledStatuses)
                        ->groupBy('order_id'),
                    'payments_sum',
                    'payments_sum.order_id',
                    '=',
                    'orders.id',
                )
                ->leftJoinSub(
                    DB::table('refunds')
                        ->select('order_id', DB::raw('SUM(amount_halalah) as completed_refund_halalah'))
                        ->where('status', 'completed')
                        ->groupBy('order_id'),
                    'refunds_sum',
                    'refunds_sum.order_id',
                    '=',
                    'orders.id',
                )
                ->where('users.role', UserRole::Customer->value)
                ->where('orders.currency', 'SAR')
                ->whereNotNull('orders.completed_at')
                ->groupBy('orders.user_id')
                ->select('orders.user_id')
                ->selectRaw('
                    SUM(
                        CASE
                            WHEN orders.channel = \'salla_import\'
                                OR (orders.wallet_halalah + COALESCE(payments_sum.captured_halalah, 0)) >= orders.total_halalah
                            THEN CASE
                                WHEN orders.total_halalah > COALESCE(refunds_sum.completed_refund_halalah, 0)
                                THEN orders.total_halalah - COALESCE(refunds_sum.completed_refund_halalah, 0)
                                ELSE 0
                            END
                            ELSE 0
                        END
                    ) as lifetime_spend
                ');

            /** @var array<string, int|string> $tierCounts */
            $tierCounts = DB::query()
                ->fromSub($orderSpends, 'user_spends')
                ->join('loyalty_tiers as current_tier', function ($join): void {
                    $join->where('current_tier.is_active', '=', true)
                        ->whereColumn('current_tier.minimum_lifetime_spend_halalah', '<=', 'user_spends.lifetime_spend')
                        ->whereNotExists(function ($query): void {
                            $query->select(DB::raw(1))
                                ->from('loyalty_tiers as higher_tier')
                                ->where('higher_tier.is_active', '=', true)
                                ->whereColumn('higher_tier.minimum_lifetime_spend_halalah', '<=', 'user_spends.lifetime_spend')
                                ->where(function ($subQuery): void {
                                    $subQuery->whereColumn('higher_tier.minimum_lifetime_spend_halalah', '>', 'current_tier.minimum_lifetime_spend_halalah')
                                        ->orWhere(function ($rankQuery): void {
                                            $rankQuery->whereColumn('higher_tier.minimum_lifetime_spend_halalah', '=', 'current_tier.minimum_lifetime_spend_halalah')
                                                ->whereColumn('higher_tier.rank', '>', 'current_tier.rank');
                                        });
                                });
                        });
                })
                ->select(['current_tier.key as tier_key'])
                ->selectRaw('COUNT(*) as aggregate_count')
                ->groupBy('current_tier.key')
                ->pluck('aggregate_count', 'tier_key')
                ->all();

            $assignedCustomers = 0;
            foreach ($tiers as $tier) {
                $count = (int) ($tierCounts[$tier->key] ?? 0);
                $counts[$tier->key] = $count;
                $assignedCustomers += $count;
            }

            // Customers without completed orders fall into the base (rank-1 / spend-0) tier.
            $remainingCustomers = max(0, $totalCustomers - $assignedCustomers);
            if (isset($counts[$baseTierKey])) {
                $counts[$baseTierKey] += $remainingCustomers;
            }

            return $counts;
        });
    }
}
