<?php

namespace App\Actions\Checkout;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentManager;
use App\Support\OrderClosingNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A customer walks away from an unpaid order on purpose.
 *
 * The same release-and-cancel path the expiry job uses, taken now instead of
 * after the grace period, so the wallet balance and the coupon reservation
 * come back immediately (owner decision, 2026-09-10). Only the owner may do
 * it, and only while nothing has been paid: anything past PendingPayment is
 * refused rather than guessed at.
 */
final class CancelPendingOrder
{
    public function __construct(
        private readonly ReleaseOrderWalletFunds $releaseOrderWalletFunds,
        private readonly PaymentManager $payments,
    ) {}

    /**
     * @throws CheckoutUnavailable when the order is no longer pending payment
     */
    public function execute(User $customer, Order $order): Order
    {
        $cancelled = DB::transaction(function () use ($customer, $order): Order {
            $locked = Order::query()
                ->whereKey($order->getKey())
                ->where('user_id', $customer->id)
                ->lockForUpdate()
                ->sole();

            if ($locked->status !== OrderStatus::PendingPayment || $locked->paid_at !== null) {
                throw new CheckoutUnavailable('Only an unpaid order can be cancelled.');
            }

            $settled = $locked->payments()
                ->whereIn('status', [
                    PaymentStatus::Paid->value,
                    PaymentStatus::Authorized->value,
                    PaymentStatus::PartiallyRefunded->value,
                    PaymentStatus::Refunded->value,
                ])
                ->exists();

            if ($settled) {
                throw new CheckoutUnavailable('Only an unpaid order can be cancelled.');
            }

            $this->releaseOrderWalletFunds->execute($locked, 'customer_cancelled');

            $locked->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            $locked->items()->update(['status' => OrderItemStatus::Cancelled->value]);

            $locked->payments()
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Cancelled->value]);

            $locked->statusHistory()->create([
                'status' => OrderStatusHistoryStatus::Cancelled,
                ...OrderClosingNote::reason('customer_cancelled'),
                'metadata' => ['source' => 'customer'],
            ]);

            return $locked;
        });

        $this->cancelGatewayInvoice($cancelled);

        return $cancelled->fresh() ?? $cancelled;
    }

    /**
     * Close the hosted invoice so the old payment link cannot charge the
     * customer for an order they just cancelled. Best effort, after commit.
     */
    private function cancelGatewayInvoice(Order $order): void
    {
        $payment = $order->payments()
            ->where('provider', 'paylink')
            ->whereNotNull('provider_payment_id')
            ->where('provider_payment_id', '!=', '')
            ->latest('id')
            ->first();

        if (! $payment instanceof Payment) {
            return;
        }

        try {
            $this->payments->gateway()->cancelInvoice((string) $payment->provider_payment_id);
        } catch (Throwable $exception) {
            Log::warning("Paylink invoice for customer-cancelled order [{$order->order_number}] could not be cancelled at the gateway.", [
                'order_public_id' => (string) $order->public_id,
                'provider_payment_id' => $payment->provider_payment_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
