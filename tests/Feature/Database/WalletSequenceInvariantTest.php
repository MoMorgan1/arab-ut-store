<?php

use App\Models\WalletAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function walletSequenceMigration(): object
{
    return require database_path('migrations/2026_08_15_000001_add_wallet_entry_sequence.php');
}

function insertLegacyWalletEntry(
    int $walletAccountId,
    string $createdAt,
    ?string $reference = null,
): int {
    return DB::table('wallet_entries')->insertGetId([
        'public_id' => (string) str()->ulid(),
        'wallet_account_id' => $walletAccountId,
        'type' => 'credit',
        'amount_halalah' => 500,
        'balance_after_halalah' => 500,
        'reference' => $reference,
        'metadata' => '[]',
        'created_at' => $createdAt,
    ]);
}

test('the migration backfills a deterministic sequence per wallet', function (): void {
    $migration = walletSequenceMigration();
    $migration->down();

    $firstAccount = WalletAccount::factory()->create();
    $secondAccount = WalletAccount::factory()->create();
    $second = insertLegacyWalletEntry($firstAccount->id, '2026-08-15 10:00:00');
    $first = insertLegacyWalletEntry($firstAccount->id, '2026-08-14 10:00:00');
    $third = insertLegacyWalletEntry($firstAccount->id, '2026-08-15 10:00:00');
    $other = insertLegacyWalletEntry($secondAccount->id, '2026-08-16 10:00:00');

    $migration->up();

    expect(DB::table('wallet_entries')->where('id', $first)->value('sequence'))->toBe(1)
        ->and(DB::table('wallet_entries')->where('id', $second)->value('sequence'))->toBe(2)
        ->and(DB::table('wallet_entries')->where('id', $third)->value('sequence'))->toBe(3)
        ->and(DB::table('wallet_entries')->where('id', $other)->value('sequence'))->toBe(1);
});

test('wallet sequences are required and unique only within their wallet', function (): void {
    $firstAccount = WalletAccount::factory()->create();
    $secondAccount = WalletAccount::factory()->create();

    insertSequencedWalletEntry($firstAccount->id, 1, 'wallet-one-entry-one');
    insertSequencedWalletEntry($secondAccount->id, 1, 'wallet-two-entry-one');

    expect(Schema::hasIndex(
        'wallet_entries',
        ['wallet_account_id', 'sequence'],
        'unique',
    ))->toBeTrue()
        ->and(fn () => insertSequencedWalletEntry($firstAccount->id, 1, 'duplicate-sequence'))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('wallet_entries')->insert([
            'public_id' => (string) str()->ulid(),
            'wallet_account_id' => $firstAccount->id,
            'type' => 'credit',
            'amount_halalah' => 500,
            'balance_after_halalah' => 500,
            'reference' => 'missing-sequence',
            'metadata' => '[]',
            'created_at' => now(),
        ]))->toThrow(QueryException::class);
});

test('reference remains the global idempotency key and the ledger stays immutable', function (): void {
    $firstAccount = WalletAccount::factory()->create();
    $secondAccount = WalletAccount::factory()->create();
    $entryId = insertSequencedWalletEntry($firstAccount->id, 1, 'same-operation');

    expect(fn () => insertSequencedWalletEntry($secondAccount->id, 1, 'same-operation'))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('wallet_entries')->where('id', $entryId)->update([
            'sequence' => 2,
        ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('wallet_entries')->where('id', $entryId)->delete())
        ->toThrow(QueryException::class);
});

function insertSequencedWalletEntry(int $walletAccountId, int $sequence, string $reference): int
{
    return DB::table('wallet_entries')->insertGetId([
        'public_id' => (string) str()->ulid(),
        'wallet_account_id' => $walletAccountId,
        'type' => 'credit',
        'amount_halalah' => 500,
        'balance_after_halalah' => 500,
        'sequence' => $sequence,
        'reference' => $reference,
        'metadata' => '[]',
        'created_at' => now(),
    ]);
}
