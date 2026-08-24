<?php

namespace App\Actions\Checkout;

use App\Enums\WalletEntryType;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Models\Order;
use App\Models\WalletEntry;

/**
 * Return the wallet money an order is holding, when that order is cancelled.
 *
 * PlaceOrder debits the wallet at placement whenever any balance is applied -
 * not only when it covers the whole order - so an order sitting at
 * PendingPayment (or Received, if the wallet paid for all of it) holds real
 * customer money. Cancelling it without giving that money back destroys it:
 * RefundPaylinkOrder is the only other credit path and it refuses cancelled
 * orders, and requires a captured Paylink payment a wallet-funded order does
 * not have.
 *
 * There are four ways an order gets cancelled - the hourly expiry job, a
 * cancelled invoice arriving through reconciliation, an admin cancelling from
 * the orders page, and the same reconcile reached from the return URL - so this
 * lives in one place and every one of them calls it. Fixing a single path is
 * how the hole stayed open the first time.
 *
 * Idempotent on the order: the entry reference is checked before and after the
 * account lock, so a retry, a racing job and a double-submitted admin action
 * cannot credit twice.
 */
final class ReleaseOrderWalletFunds
{
    public function __construct(
        private readonly WalletLedgerWriter $walletLedgerWriter,
    ) {}

    /**
     * @param  Order  $order  must already be locked by the caller, inside the
     *                        same transaction that cancels it
     * @return int the halalah returned, 0 when there was nothing to return
     */
    public function execute(Order $order, string $reason): int
    {
        $walletHalalah = (int) $order->wallet_halalah;

        if ($walletHalalah <= 0) {
            return 0;
        }

        $reference = "order-wallet-released:{$order->id}";

        if ($this->walletLedgerWriter->lockedEntryByReference($reference) instanceof WalletEntry) {
            return 0;
        }

        $account = $this->walletLedgerWriter->lockAccountFor((int) $order->user_id);

        // Re-check after taking the account lock, the way RefundPaylinkOrder
        // does: two writers racing on the same order must not both credit.
        if ($this->walletLedgerWriter->lockedEntryByReference($reference) instanceof WalletEntry) {
            return 0;
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
                'reason' => $reason,
            ],
        ]);

        return $walletHalalah;
    }
}
