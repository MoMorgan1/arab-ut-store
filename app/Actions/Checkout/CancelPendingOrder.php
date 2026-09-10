<?php

namespace App\Actions\Checkout;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Exceptions\Payments\PaymentConfigurationException;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentManager;
use App\Support\OrderClosingNote;
use Illuminate\Support\Facades\DB;

/**
 * A customer walks away from an unpaid order on purpose.
 *
 * The same release-and-cancel path the expiry job uses, taken now instead of
 * after the grace period, so the wallet balance and the coupon reservation
 * come back immediately (owner decision, 2026-09-10). Only the owner may do
 * it, and only while nothing has been paid.
 *
 * Money safety, in order: ask Paylink first (a paid invoice becomes a paid
 * order, never a cancelled one with a refunded wallet); then, under the row
 * lock, close the hosted invoice at the gateway BEFORE anything is written
 * locally, so a cancelled order can never keep a chargeable invoice. If the
 * gateway refuses or cannot be reached, nothing changes and the customer can
 * try again.
 */
final class CancelPendingOrder
{
    public function __construct(
        private readonly ReleaseOrderWalletFunds $releaseOrderWalletFunds,
        private readonly ReconcilePaylinkPayment $reconcilePaylinkPayment,
        private readonly CloseGatewayInvoice $closeGatewayInvoice,
        private readonly PaymentManager $payments,
    ) {}

    /**
     * @throws CheckoutUnavailable when the order is no longer pending payment
     * @throws PaymentGatewayException|PaymentConfigurationException when Paylink cannot confirm or close the invoice
     */
    public function execute(User $customer, Order $order): Order
    {
        $invoice = $this->closeGatewayInvoice->pendingInvoice($order);

        if ($invoice instanceof Payment) {
            $this->reconcilePaylinkPayment->execute($invoice);
        }

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

            // Gateway first, while the row is locked: a failure here rolls
            // everything back and the order stays exactly as it was.
            $this->closeGatewayInvoice->execute($locked, $this->payments);

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

        return $cancelled->fresh() ?? $cancelled;
    }
}
