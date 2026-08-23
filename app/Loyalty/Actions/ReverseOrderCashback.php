<?php

namespace App\Loyalty\Actions;

use App\Enums\WalletEntryType;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Models\Order;
use App\Models\Refund;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use Illuminate\Support\Facades\DB;

/**
 * Reverses the cashback credited for an order when one of its refunds
 * completes. Refunds are full-only today, so the reversal removes the credited
 * amount capped at the current balance (which may already have been spent).
 */
final readonly class ReverseOrderCashback
{
    public function __construct(
        private WalletLedgerWriter $writer,
    ) {}

    /**
     * Returns the reversal entry (or the existing one on replay), or null when
     * there is nothing to reverse.
     */
    public function execute(Refund $refund): ?WalletEntry
    {
        if (! (bool) config('store.features.loyalty_enabled')) {
            return null;
        }

        $order = $refund->order;

        if (! $order instanceof Order) {
            return null;
        }

        return DB::transaction(function () use ($order, $refund): ?WalletEntry {
            $accrual = $this->writer->lockedEntryByReference("cashback:{$order->id}");

            if (! $accrual instanceof WalletEntry) {
                return null;
            }

            $reversalReference = "cashback-reversal:{$refund->id}";
            $existingReversal = $this->writer->lockedEntryByReference($reversalReference);

            if ($existingReversal instanceof WalletEntry) {
                return $existingReversal;
            }

            // The accrual proves an account exists, so the reversal never
            // creates one as a side effect.
            $account = $this->writer->lockedAccountById((int) $accrual->wallet_account_id);

            if (! $account instanceof WalletAccount) {
                return null;
            }

            $amount = min((int) $accrual->amount_halalah, (int) $account->balance_halalah);

            if ($amount <= 0) {
                return null;
            }

            /** @var WalletEntry */
            return $this->writer->append($account, [
                'type' => WalletEntryType::CashbackReversal,
                'amount_halalah' => $amount,
                'balance_delta_halalah' => -$amount,
                'order_id' => $order->id,
                'refund_id' => $refund->id,
                'created_by_user_id' => null,
                'reference' => $reversalReference,
                'metadata' => [
                    'accrual_entry_id' => $accrual->id,
                    'credited_halalah' => (int) $accrual->amount_halalah,
                ],
            ])['entry'];
        }, attempts: 3);
    }
}
