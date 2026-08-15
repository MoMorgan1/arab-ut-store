<?php

use App\Enums\OrderStatus;
use App\Enums\WalletEntryType;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;

test('the bilingual wallet destinations render an explicit no-wallet state', function (
    string $path,
    string $locale,
): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page
            ->component('account/wallet')
            ->where('locale', $locale)
            ->where('wallet.exists', false)
            ->where('wallet.balance', null)
            ->where('wallet.entries', [])
            ->where('wallet.pagination.total', 0)
            ->where('accountNavigation', fn ($items): bool => collect($items)->pluck('key')->all() === [
                'overview',
                'orders',
                'wallet',
            ]));

    expect($response->inertiaPage()['encryptHistory'] ?? false)->toBeTrue();
})->with([
    'Arabic wallet' => ['/my-account/wallet', 'ar'],
    'English wallet' => ['/en/my-account/wallet', 'en'],
]);

test('the wallet ledger is owner scoped ordered by newest sequence and exact for large amounts', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $account = WalletAccount::factory()->for($owner)->create([
        'balance_halalah' => 9_007_199_254_740_991,
    ]);
    $otherAccount = WalletAccount::factory()->for($other)->create();
    $order = Order::factory()->for($owner)->create([
        'order_number' => 'UT-00000071',
        'status' => OrderStatus::Completed,
    ]);

    foreach (range(1, 11) as $sequence) {
        WalletEntry::factory()->for($account, 'walletAccount')->create([
            'sequence' => $sequence,
            'type' => match ($sequence % 4) {
                0 => WalletEntryType::Debit,
                1 => WalletEntryType::Credit,
                2 => WalletEntryType::Refund,
                default => WalletEntryType::Adjustment,
            },
            'amount_halalah' => $sequence === 11 ? 9_007_199_254_740_991 : $sequence * 100,
            'balance_after_halalah' => $sequence * 1_000,
            'order_id' => $sequence === 11 ? $order->id : null,
            'created_at' => now()->addMinutes($sequence),
        ]);
    }

    WalletEntry::factory()->for($otherAccount, 'walletAccount')->create([
        'sequence' => 99,
        'reference' => 'must-not-leak',
        'metadata' => ['private' => 'must-not-leak'],
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/wallet')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('account/wallet')
            ->where('wallet.exists', true)
            ->where('wallet.balance', [
                'amountMinor' => '9007199254740991',
                'currency' => 'SAR',
            ])
            ->where('wallet.pagination.currentPage', 1)
            ->where('wallet.pagination.lastPage', 2)
            ->where('wallet.pagination.perPage', 10)
            ->where('wallet.pagination.total', 11)
            ->has('wallet.entries', 10)
            ->where('wallet.entries.0.sequence', 11)
            ->where('wallet.entries.0.type', 'adjustment')
            ->where('wallet.entries.0.effect', 'neutral')
            ->where('wallet.entries.0.amount.amountMinor', '9007199254740991')
            ->where('wallet.entries.0.order.number', 'UT-00000071')
            ->where('wallet.entries.0.order.url', '/en/my-account/orders/'.$order->public_id)
            ->where('wallet.entries.9.sequence', 2));

    $payload = json_encode($response->inertiaPage(), JSON_THROW_ON_ERROR);

    expect($payload)
        ->not->toContain('must-not-leak')
        ->not->toContain('"reference":')
        ->not->toContain('"metadata":');
});

test('a zero wallet remains distinct from an account without a wallet', function (): void {
    $user = User::factory()->create();
    WalletAccount::factory()->for($user)->create(['balance_halalah' => 0]);

    $this->actingAs($user)
        ->get('/my-account/wallet')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wallet.exists', true)
            ->where('wallet.balance', ['amountMinor' => '0', 'currency' => 'SAR'])
            ->where('wallet.entries', []));
});
