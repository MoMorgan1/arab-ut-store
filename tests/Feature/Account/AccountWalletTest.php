<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WalletEntryType;
use App\Models\LoyaltyTier;
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
            ->where('wallet.lifetimeCashback', ['amountMinor' => '0', 'currency' => 'SAR'])
            ->where('wallet.entries', [])
            ->where('wallet.pagination.total', 0)
            ->where('accountNavigation', fn ($items): bool => collect($items)->pluck('key')->all() === [
                'overview',
                'orders',
                'wallet',
                'profile',
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
            ->where('wallet.entries.0.order.url', '/en/my-account/orders/'.$order->order_number)
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

test('cashback and its reversal land in the single wallet balance and stay visible in the ledger', function (): void {
    $user = User::factory()->create();
    $account = WalletAccount::factory()->for($user)->create(['balance_halalah' => 3_500]);

    WalletEntry::factory()->for($account, 'walletAccount')->create([
        'sequence' => 1,
        'type' => WalletEntryType::Cashback,
        'amount_halalah' => 5_000,
        'balance_after_halalah' => 5_000,
    ]);

    WalletEntry::factory()->for($account, 'walletAccount')->create([
        'sequence' => 2,
        'type' => WalletEntryType::CashbackReversal,
        'amount_halalah' => 1_500,
        'balance_after_halalah' => 3_500,
    ]);

    $this->actingAs($user)
        ->get('/my-account/wallet')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wallet.exists', true)
            ->where('wallet.balance', ['amountMinor' => '3500', 'currency' => 'SAR'])
            ->has('wallet.entries', 2)
            ->where('wallet.entries.0.type', 'cashback_reversal')
            ->where('wallet.entries.0.effect', 'debit')
            ->where('wallet.entries.1.type', 'cashback')
            ->where('wallet.entries.1.effect', 'credit'));
});

test('the wallet destination presents loyalty overview with active tiers and lifetime cashback', function (): void {
    $user = User::factory()->create();

    LoyaltyTier::query()->create([
        'key' => 'bronze',
        'name_ar' => 'برونزي',
        'name_en' => 'Bronze',
        'rank' => 1,
        'minimum_lifetime_spend_halalah' => 0,
        'cashback_basis_points' => 100,
        'is_active' => true,
    ]);
    LoyaltyTier::query()->create([
        'key' => 'silver',
        'name_ar' => 'فضي',
        'name_en' => 'Silver',
        'rank' => 2,
        'minimum_lifetime_spend_halalah' => 10_000,
        'cashback_basis_points' => 200,
        'is_active' => true,
    ]);
    LoyaltyTier::query()->create([
        'key' => 'gold',
        'name_ar' => 'ذهبي',
        'name_en' => 'Gold',
        'rank' => 3,
        'minimum_lifetime_spend_halalah' => 25_000,
        'cashback_basis_points' => 300,
        'is_active' => true,
    ]);

    $order = Order::factory()->for($user)->create([
        'order_number' => 'UT-12345678',
        'status' => OrderStatus::Completed,
        'subtotal_halalah' => 15_000,
        'payment_halalah' => 15_000,
        'total_halalah' => 15_000,
        'currency' => 'SAR',
        'completed_at' => now(),
    ]);
    $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => (string) str()->ulid(),
        'status' => PaymentStatus::Paid,
        'currency' => 'SAR',
        'amount_halalah' => 15_000,
        'captured_halalah' => 15_000,
        'refunded_halalah' => 0,
        'idempotency_key' => (string) str()->ulid(),
    ]);

    $this->actingAs($user)
        ->get('/my-account/wallet')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('loyalty.tiers', 3)
            ->where('loyalty.currentTier.key', 'silver')
            ->where('loyalty.nextTier.key', 'gold')
            ->where('loyalty.progressPercent', fn ($val): bool => is_int($val))
            ->has('loyalty.cashback.lifetime')
            ->missing('loyalty.cashback.entries'));
});

test('the wallet destination presents null loyalty when no tiers exist', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/my-account/wallet')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('loyalty', null));
});
