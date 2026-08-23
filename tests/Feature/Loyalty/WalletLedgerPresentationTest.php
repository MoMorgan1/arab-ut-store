<?php

use App\Enums\OrderStatus;
use App\Enums\WalletEntryType;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;

function cashbackLedgerPage(User $owner, string $path): array
{
    return test()->actingAs($owner)->get($path)->assertOk()->inertiaPage()['props']['wallet'];
}

test('the wallet ledger presents cashback as credit and reversals as debit in both locales', function (): void {
    $owner = User::factory()->create();
    $account = WalletAccount::factory()->for($owner)->create(['balance_halalah' => 700]);
    $order = Order::factory()->for($owner)->create([
        'order_number' => 'UT-CASHBACK-1',
        'status' => OrderStatus::Completed,
    ]);

    WalletEntry::factory()->for($account, 'walletAccount')->create([
        'sequence' => 2,
        'type' => WalletEntryType::Cashback,
        'amount_halalah' => 998,
        'balance_after_halalah' => 998,
        'reference' => 'cashback:401',
        'order_id' => $order->id,
        'created_at' => now()->addMinutes(2),
    ]);
    WalletEntry::factory()->for($account, 'walletAccount')->create([
        'sequence' => 1,
        'type' => WalletEntryType::CashbackReversal,
        'amount_halalah' => 298,
        'balance_after_halalah' => 700,
        'reference' => 'cashback-reversal:900',
        'created_at' => now()->addMinutes(3),
    ]);

    $arabicWallet = cashbackLedgerPage($owner, '/my-account/wallet');
    $englishWallet = cashbackLedgerPage($owner, '/en/my-account/wallet');

    expect($arabicWallet['entries'])->toHaveCount(2)
        ->and($arabicWallet['balance']['amountMinor'])->toBe('700');

    foreach ([$arabicWallet['entries'], $englishWallet['entries']] as $entries) {
        [$accrual, $reversal] = $entries;

        expect($accrual['type'])->toBe('cashback')
            ->and($accrual['effect'])->toBe('credit')
            ->and($accrual['amount']['amountMinor'])->toBe('998')
            ->and($accrual['order']['number'])->toBe('UT-CASHBACK-1')
            ->and($reversal['type'])->toBe('cashback_reversal')
            ->and($reversal['effect'])->toBe('debit')
            ->and($reversal['amount']['amountMinor'])->toBe('298');
    }
});

test('the new wallet entry types carry bilingual labels', function (): void {
    expect(__('account.wallet.cashback', [], 'ar'))->toBe('كاش باك')
        ->and(__('account.wallet.cashback_reversal', [], 'ar'))->toBe('استرجاع كاش باك')
        ->and(__('account.wallet.cashback', [], 'en'))->toBe('Cashback')
        ->and(__('account.wallet.cashback_reversal', [], 'en'))->toBe('Cashback reversal');
});
