<?php

namespace App\Loyalty\Support;

use App\Enums\WalletEntryType;
use App\Models\WalletAccount;
use App\Models\WalletEntry;

/**
 * The single writer for the append-only wallet ledger.
 *
 * Every write locks the wallet_accounts row (creating it if missing), allocates
 * a per-account sequence of max(sequence) + 1, inserts the entry, and applies
 * the balance delta — all inside the caller's transaction.
 */
final class WalletLedgerWriter
{
    /**
     * Locks the account row for this user so concurrent writers serialise on
     * sequence allocation and balance updates. Creates a zero-balance account
     * when none exists yet; a race on creation is resolved by the unique
     * user_id index plus the caller's transaction retry.
     */
    public function lockAccountFor(int $userId): WalletAccount
    {
        $account = WalletAccount::query()->firstOrCreate(
            ['user_id' => $userId],
            ['balance_halalah' => 0],
        );

        return WalletAccount::query()
            ->whereKey($account->getKey())
            ->lockForUpdate()
            ->sole();
    }

    /** Finds an entry by its globally unique idempotency reference under lock. */
    public function lockedEntryByReference(string $reference): ?WalletEntry
    {
        /** @var WalletEntry|null */
        return WalletEntry::query()
            ->where('reference', $reference)
            ->lockForUpdate()
            ->first();
    }

    /** Locks an existing account row without ever creating one. */
    public function lockedAccountById(int $accountId): ?WalletAccount
    {
        /** @var WalletAccount|null */
        return WalletAccount::query()
            ->whereKey($accountId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Appends one immutable entry and applies the signed balance delta.
     *
     * @param  array{type: WalletEntryType, amount_halalah: int, balance_delta_halalah: int, order_id: int|null, refund_id: int|null, created_by_user_id: int|null, reference: string|null, metadata: array<string, mixed>|null}  $attributes
     * @return array{entry: WalletEntry, balance_after_halalah: int}
     */
    public function append(WalletAccount $account, array $attributes): array
    {
        $nextSequence = (int) WalletEntry::query()
            ->where('wallet_account_id', $account->getKey())
            ->max('sequence') + 1;

        $balanceAfter = (int) $account->balance_halalah + $attributes['balance_delta_halalah'];

        $entry = WalletEntry::query()->create([
            'wallet_account_id' => $account->getKey(),
            'order_id' => $attributes['order_id'],
            'refund_id' => $attributes['refund_id'],
            'created_by_user_id' => $attributes['created_by_user_id'],
            'type' => $attributes['type'],
            'sequence' => $nextSequence,
            'amount_halalah' => $attributes['amount_halalah'],
            'balance_after_halalah' => $balanceAfter,
            'reference' => $attributes['reference'],
            'metadata' => $attributes['metadata'],
        ]);

        $account->forceFill(['balance_halalah' => $balanceAfter])->save();

        return ['entry' => $entry, 'balance_after_halalah' => $balanceAfter];
    }
}
