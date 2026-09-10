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
use App\Services\Payments\PaymentManager;
use App\Support\OrderClosingNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 *
 * An order that reached Paylink is asked about first: the gateway is the only
 * party that knows whether the customer paid while a callback was lost. Paid
 * invoices are reconciled into Received, cancelled ones into Cancelled, and an
 * invoice still pending past the grace period is closed at the gateway and
 * only then cancelled here. When Paylink cannot be reached, or refuses to
 * close the invoice, the order is left alone for the next run: a stale
 * "unpaid" row is far cheaper than a stranded payment (owner decision,
 * 2026-09-10).
 */
final class ExpireAbandonedCheckouts
{
    /**
     * How long a customer has to complete payment before the order is released.
     *
     * Three hours (owner decision, 2026-09-10): long enough for a card or a
     * bank app to come back, short enough that the customer's order list does
     * not carry a dead "unpaid" row for a day.
     */
    public const GRACE_HOURS = 3;

    public function __construct(
        private readonly ReleaseOrderWalletFunds $releaseOrderWalletFunds,
        private readonly ReconcilePaylinkPayment $reconcilePaylinkPayment,
        private readonly CloseGatewayInvoice $closeGatewayInvoice,
        private readonly PaymentManager $payments,
    ) {}

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
                    $cancelled += $this->expire($order);
                }
            });

        return $cancelled;
    }

    private function expire(Order $order): int
    {
        $invoice = $this->closeGatewayInvoice->pendingInvoice($order);

        // Ask Paylink before touching an order it knows about. The reconcile
        // moves a paid invoice to Received and a cancelled one to Cancelled on
        // its own; only a still-pending invoice falls through to the cancel
        // below.
        if ($invoice instanceof Payment) {
            try {
                $this->reconcilePaylinkPayment->execute($invoice);
            } catch (PaymentGatewayException|PaymentConfigurationException|CheckoutUnavailable $exception) {
                $this->leaveForNextRun($order, $invoice, 'reconciled', $exception);

                return 0;
            }
        }

        try {
            return $this->cancel($order);
        } catch (PaymentGatewayException|PaymentConfigurationException $exception) {
            $this->leaveForNextRun($order, $invoice, 'closed at the gateway', $exception);

            return 0;
        }
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
            // between the query above and this transaction, or the reconcile
            // above may already have moved the order on.
            if (! $locked instanceof Order || $locked->status !== OrderStatus::PendingPayment) {
                return 0;
            }

            // Never cancel an order whose money actually arrived.
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

            // Gateway first, while the row is locked: if Paylink will not close
            // the invoice, this throws, the transaction rolls back, and the
            // order stays pending for the next run.
            $this->closeGatewayInvoice->execute($locked, $this->payments);

            // PlaceOrder debits the wallet at placement whenever any balance is
            // applied - not only when it covers the whole order - so a
            // part-wallet order being cancelled here has real customer money
            // against it. Give it back in the same transaction as the
            // cancellation, or this job quietly destroys balances.
            $this->releaseOrderWalletFunds->execute($locked, 'checkout_expired');

            $locked->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            // Items follow the order, as both the reconciler and the admin
            // transition do; otherwise they sit at pending_payment underneath a
            // cancelled order.
            $locked->items()->update(['status' => OrderItemStatus::Cancelled->value]);

            $locked->payments()
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Cancelled->value]);

            $locked->statusHistory()->create([
                'status' => OrderStatusHistoryStatus::Cancelled,
                ...OrderClosingNote::reason('checkout_expired'),
                'metadata' => ['source' => 'checkout_expiry'],
            ]);

            return 1;
        });
    }

    private function leaveForNextRun(Order $order, ?Payment $invoice, string $step, Throwable $exception): void
    {
        Log::warning("Abandoned checkout [{$order->order_number}] could not be {$step}; leaving it for the next run.", [
            'order_public_id' => (string) $order->public_id,
            'provider_payment_id' => $invoice?->provider_payment_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
