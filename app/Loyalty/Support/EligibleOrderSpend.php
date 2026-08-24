<?php

namespace App\Loyalty\Support;

use App\Enums\PaymentStatus;
use App\Models\LoyaltyTier;
use App\Models\Order;
use App\Models\User;

/**
 * Shared loyalty eligibility rules.
 *
 * An order counts toward lifetime eligible spend when it belongs to the user,
 * is in SAR, has been completed, and was fully paid (wallet usage plus settled
 * gateway payments covers the order total). Its contribution is the total minus
 * completed refunds, floored at zero.
 */
final class EligibleOrderSpend
{
    private const SETTLED_PAYMENT_STATUSES = [
        PaymentStatus::Paid,
        PaymentStatus::PartiallyRefunded,
        PaymentStatus::Refunded,
    ];

    /** @return list<string> */
    private function settledStatusValues(): array
    {
        return array_map(fn (PaymentStatus $status): string => $status->value, self::SETTLED_PAYMENT_STATUSES);
    }

    /**
     * Lifetime eligible spend for a user, optionally excluding one order so the
     * tier an order earns can be resolved without the order itself.
     */
    public function lifetime(int $userId, ?int $excludingOrderId = null): int
    {
        $query = Order::query()
            ->select(['id', 'total_halalah', 'wallet_halalah'])
            ->where('user_id', $userId)
            ->where('currency', 'SAR')
            ->whereNotNull('completed_at');

        if ($excludingOrderId !== null) {
            $query->whereKeyNot($excludingOrderId);
        }

        $orders = $query
            ->withSum([
                'payments as settled_payment_halalah' => fn ($q) => $q->whereIn(
                    'status',
                    $this->settledStatusValues(),
                ),
            ], 'captured_halalah')
            ->withSum([
                'refunds as completed_refund_halalah' => fn ($q) => $q->where('status', 'completed'),
            ], 'amount_halalah')
            ->get();

        return $orders->sum(function (Order $order): int {
            $total = (int) $order->getAttribute('total_halalah');
            $wallet = (int) $order->getAttribute('wallet_halalah');
            $settledPayment = (int) ($order->getAttribute('settled_payment_halalah') ?? 0);

            if ($wallet + $settledPayment < $total) {
                return 0;
            }

            $completedRefunds = (int) ($order->getAttribute('completed_refund_halalah') ?? 0);

            return max(0, $total - $completedRefunds);
        });
    }

    /**
     * Whether a single order is fully paid under the shared rule: wallet usage
     * plus settled gateway payments must cover the order total.
     *
     * Imported Salla orders deliberately DO count toward lifetime spend above:
     * a customer who spent thousands before the migration keeps the tier they
     * earned, and their rate on future orders reflects it. They must never
     * settle, though - settlement is what triggers accrual, and paying cashback
     * on history the store already fulfilled elsewhere would mint real money.
     */
    public function fullySettled(Order $order): bool
    {
        if ($order->channel === 'salla_import') {
            return false;
        }

        $settledPayment = (int) $order->payments()
            ->whereIn('status', $this->settledStatusValues())
            ->sum('captured_halalah');

        return ((int) $order->wallet_halalah + $settledPayment) >= (int) $order->total_halalah;
    }

    /** The highest active tier whose minimum is covered by the given spend. */
    public function reachedTier(int $eligibleSpendHalalah): ?LoyaltyTier
    {
        return LoyaltyTier::query()
            ->where('is_active', true)
            ->where('minimum_lifetime_spend_halalah', '<=', $eligibleSpendHalalah)
            ->orderByDesc('minimum_lifetime_spend_halalah')
            ->orderByDesc('rank')
            ->first();
    }
}
