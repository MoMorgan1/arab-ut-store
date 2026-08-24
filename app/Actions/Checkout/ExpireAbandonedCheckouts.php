<?php

namespace App\Actions\Checkout;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WalletEntryType;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Models\Order;
use App\Models\WalletEntry;
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

    public function __construct(
        private readonly WalletLedgerWriter $walletLedgerWriter,
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

            // A local payment row only reports what WE recorded. If an invoice
            // was raised at the gateway the customer may have paid it while a
            // webhook was lost, and reconciliation only ever runs from a
            // controller - nothing scheduled re-checks it. Cancelling such an
            // order would charge the customer and deliver nothing, and a later
            // reconcile cannot rescue it, because it only transitions orders
            // that are still PendingPayment. Leave these alone rather than
            // guess: a stuck coupon hold is far cheaper than a stranded payment.
            $hasGatewayInvoice = $locked->payments()
                ->whereNotNull('provider_payment_id')
                ->exists();

            if ($hasGatewayInvoice) {
                return 0;
            }

            // PlaceOrder debits the wallet at placement whenever any balance is
            // applied - not only when it covers the whole order - so a
            // part-wallet order being cancelled here has real customer money
            // against it. Give it back in the same transaction as the
            // cancellation, or this job quietly destroys balances.
            $this->creditWalletBack($locked);

            $locked->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            // Items follow the order, as both the reconciler and the admin
            // transition do; otherwise they sit at pending_payment underneath a
            // cancelled order.
            $locked->items()->update(['status' => OrderItemStatus::Cancelled->value]);

            return 1;
        });
    }

    private function creditWalletBack(Order $order): void
    {
        $walletHalalah = (int) $order->wallet_halalah;

        if ($walletHalalah <= 0) {
            return;
        }

        $reference = "order-wallet-expired:{$order->id}";

        if ($this->walletLedgerWriter->lockedEntryByReference($reference) instanceof WalletEntry) {
            return;
        }

        $account = $this->walletLedgerWriter->lockAccountFor((int) $order->user_id);

        // Re-check after taking the account lock, the way RefundPaylinkOrder
        // does: two runs racing on the same order must not credit twice.
        if ($this->walletLedgerWriter->lockedEntryByReference($reference) instanceof WalletEntry) {
            return;
        }

        $this->walletLedgerWriter->append($account, [
            'type' => WalletEntryType::Refund,
            'amount_halalah' => $walletHalalah,
            'balance_delta_halalah' => $walletHalalah,
            'order_id' => $order->id,
            'refund_id' => null,
            'created_by_user_id' => null,
            'reference' => $reference,
            'metadata' => [
                'order_number' => $order->order_number,
                'reason' => 'checkout_expired',
            ],
        ]);
    }
}
