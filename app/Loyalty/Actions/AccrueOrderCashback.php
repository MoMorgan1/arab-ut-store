<?php

namespace App\Loyalty\Actions;

use App\Enums\WalletEntryType;
use App\Loyalty\Support\EligibleOrderSpend;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Models\LoyaltyTier;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletEntry;
use Illuminate\Support\Facades\DB;

/**
 * Accrues tiered cashback when an order completes.
 *
 * The cashback basis is the amount the customer actually paid for the order:
 * total_halalah − wallet_halalah. Order discounts are NOT subtracted again
 * because PlaceOrder already writes them reflected in total_halalah (it sets
 * discount_halalah = 0 and total = subtotal today and never creates separate
 * order_discounts rows), so subtracting them would double-count.
 *
 * The tier applied is the one reached by lifetime eligible spend excluding this
 * very order, so a completing order never promotes its own rate.
 */
final readonly class AccrueOrderCashback
{
    public function __construct(
        private EligibleOrderSpend $eligibleOrderSpend,
        private WalletLedgerWriter $writer,
    ) {}

    /**
     * Returns the accrued entry, or the existing entry when called again for an
     * already-accrued order, or null when accrual does not apply.
     */
    public function execute(Order $order): ?WalletEntry
    {
        if (! (bool) config('store.features.loyalty_enabled')) {
            return null;
        }

        if ($order->channel === 'salla_import' || $order->currency !== 'SAR' || $order->completed_at === null) {
            return null;
        }

        $customer = $order->user;

        if (! $customer instanceof User) {
            return null;
        }

        return DB::transaction(function () use ($order, $customer): ?WalletEntry {
            $reference = "cashback:{$order->id}";

            $existing = $this->writer->lockedEntryByReference($reference);

            if ($existing instanceof WalletEntry) {
                return $existing;
            }

            if (! $this->eligibleOrderSpend->fullySettled($order)) {
                return null;
            }

            $tier = $this->eligibleOrderSpend->reachedTier(
                $this->eligibleOrderSpend->lifetime($customer->id, $order->id),
            );

            if (! $tier instanceof LoyaltyTier) {
                return null;
            }

            $basis = max(0, (int) $order->total_halalah - (int) $order->wallet_halalah);
            $amount = intdiv($basis * $tier->cashback_basis_points, 10000);

            if ($amount <= 0) {
                return null;
            }

            // The account is created only once a write is certain, then the
            // reference check repeats under the account lock so concurrent
            // accruals for the same order cannot double-insert.
            $account = $this->writer->lockAccountFor($customer->id);

            $racing = $this->writer->lockedEntryByReference($reference);

            if ($racing instanceof WalletEntry) {
                return $racing;
            }

            /** @var WalletEntry */
            return $this->writer->append($account, [
                'type' => WalletEntryType::Cashback,
                'amount_halalah' => $amount,
                'balance_delta_halalah' => $amount,
                'order_id' => $order->id,
                'refund_id' => null,
                'created_by_user_id' => null,
                'reference' => $reference,
                'metadata' => [
                    'tier_key' => $tier->key,
                    'basis_points' => $tier->cashback_basis_points,
                    'basis_halalah' => $basis,
                ],
            ])['entry'];
        }, attempts: 3);
    }
}
