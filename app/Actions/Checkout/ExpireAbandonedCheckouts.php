<?php

namespace App\Actions\Checkout;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Cancel checkouts that were started and never paid.
 *
 * An order is created as PendingPayment before the customer reaches the payment
 * page, and a coupon redemption is written at the same moment. That redemption
 * is a deliberate reservation: it stops two concurrent checkouts spending the
 * last use of a limited coupon. But nothing released it, so an abandoned
 * checkout held its reservation forever - a `usage_limit` campaign could be
 * exhausted entirely by people who never paid, accidentally or on purpose.
 *
 * Cancelling the order is what releases the hold, because the redemption count
 * in DiscountEngine excludes orders that are cancelled. The redemption row is
 * kept rather than deleted so the history stays auditable.
 */
final class ExpireAbandonedCheckouts
{
    /**
     * How long a customer has to complete payment before the order is released.
     *
     * Generous on purpose: a bank transfer can legitimately take a while, and
     * cancelling an order somebody is about to pay for is far worse than
     * holding a coupon reservation a little longer.
     */
    public const GRACE_HOURS = 24;

    /** @return int the number of orders cancelled */
    public function execute(): int
    {
        $cutoff = now()->subHours(self::GRACE_HOURS);

        $cancelled = 0;

        Order::query()
            ->where('status', OrderStatus::PendingPayment)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$cancelled): void {
                foreach ($orders as $order) {
                    $cancelled += $this->cancel($order);
                }
            });

        return $cancelled;
    }

    private function cancel(Order $order): int
    {
        return DB::transaction(function () use ($order): int {
            /** @var Order|null $locked */
            $locked = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            // Re-check under the lock: the customer may have paid in the moment
            // between the query above and this transaction.
            if (! $locked instanceof Order || $locked->status !== OrderStatus::PendingPayment) {
                return 0;
            }

            // Never cancel an order whose money actually arrived. A payment that
            // settled while the reconciler was behind would otherwise be thrown
            // away here, which is the one outcome worse than a stuck coupon.
            $settled = $locked->payments()
                ->whereIn('status', [
                    PaymentStatus::Paid->value,
                    PaymentStatus::Authorized->value,
                    PaymentStatus::PartiallyRefunded->value,
                    PaymentStatus::Refunded->value,
                ])
                ->exists();

            if ($settled || $locked->paid_at !== null) {
                return 0;
            }

            $locked->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            return 1;
        });
    }
}
