<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WalletEntryType;
use App\Models\LoyaltyTier;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;

test('guests are redirected to login from loyalty destinations', function (string $path, string $login): void {
    $this->get($path)
        ->assertRedirect($login);
})->with([
    'Arabic loyalty' => ['/my-account/loyalty', '/login'],
    'English loyalty' => ['/en/my-account/loyalty', '/en/login'],
]);

test('the bilingual loyalty destinations render a safe empty state when no tiers exist', function (
    string $path,
    string $locale,
): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page
            ->component('account/loyalty')
            ->where('locale', $locale)
            ->where('tiers', [])
            ->where('currentTier', null)
            ->where('nextTier', null)
            ->where('remaining', null)
            ->where('progressPercent', 0)
            ->where('eligibleSpend', ['amountMinor' => '0', 'currency' => 'SAR'])
            ->where('cashback.lifetime', ['amountMinor' => '0', 'currency' => 'SAR'])
            ->where('cashback.entries', [])
            ->where('accountNavigation', fn ($items): bool => collect($items)->pluck('key')->all() === [
                'overview',
                'orders',
                'wallet',
                'profile',
            ]));

    expect($response->inertiaPage()['encryptHistory'] ?? false)->toBeTrue();
})->with([
    'Arabic loyalty' => ['/my-account/loyalty', 'ar'],
    'English loyalty' => ['/en/my-account/loyalty', 'en'],
]);

test('the loyalty overview presents tiers, current tier, progress, and cashback history', function (): void {
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
    LoyaltyTier::query()->create([
        'key' => 'platinum',
        'name_ar' => 'بلاتيني',
        'name_en' => 'Platinum',
        'rank' => 4,
        'minimum_lifetime_spend_halalah' => 50_000,
        'cashback_basis_points' => 500,
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

    $account = WalletAccount::factory()->for($user)->create(['balance_halalah' => 4_000]);

    WalletEntry::factory()->for($account, 'walletAccount')->create([
        'sequence' => 1,
        'type' => WalletEntryType::Cashback,
        'amount_halalah' => 5_000,
        'balance_after_halalah' => 5_000,
        'order_id' => $order->id,
        'created_at' => now()->subHour(),
    ]);

    WalletEntry::factory()->for($account, 'walletAccount')->create([
        'sequence' => 2,
        'type' => WalletEntryType::CashbackReversal,
        'amount_halalah' => 1_000,
        'balance_after_halalah' => 4_000,
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/en/my-account/loyalty')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('account/loyalty')
            ->where('locale', 'en')
            ->has('tiers', 4)
            ->where('tiers.0.key', 'bronze')
            ->where('tiers.0.name', 'Bronze')
            ->where('tiers.0.minimum', ['amountMinor' => '0', 'currency' => 'SAR'])
            ->where('tiers.0.cashbackPercent', 1)
            ->where('tiers.1.key', 'silver')
            ->where('tiers.1.name', 'Silver')
            ->where('tiers.1.minimum', ['amountMinor' => '10000', 'currency' => 'SAR'])
            ->where('tiers.1.cashbackPercent', 2)
            ->where('tiers.2.key', 'gold')
            ->where('tiers.2.name', 'Gold')
            ->where('tiers.2.minimum', ['amountMinor' => '25000', 'currency' => 'SAR'])
            ->where('tiers.2.cashbackPercent', 3)
            ->where('tiers.3.key', 'platinum')
            ->where('tiers.3.name', 'Platinum')
            ->where('tiers.3.minimum', ['amountMinor' => '50000', 'currency' => 'SAR'])
            ->where('tiers.3.cashbackPercent', 5)
            ->where('currentTier.key', 'silver')
            ->where('currentTier.name', 'Silver')
            ->where('nextTier.key', 'gold')
            ->where('nextTier.name', 'Gold')
            ->where('remaining', ['amountMinor' => '10000', 'currency' => 'SAR'])
            ->where('progressPercent', 33)
            ->where('eligibleSpend', ['amountMinor' => '15000', 'currency' => 'SAR'])
            ->where('cashback.lifetime', ['amountMinor' => '4000', 'currency' => 'SAR'])
            ->has('cashback.entries', 2)
            ->where('cashback.entries.0.sequence', 2)
            ->where('cashback.entries.0.type', 'cashback_reversal')
            ->where('cashback.entries.0.effect', 'debit')
            ->where('cashback.entries.0.amount', ['amountMinor' => '1000', 'currency' => 'SAR'])
            ->where('cashback.entries.1.sequence', 1)
            ->where('cashback.entries.1.type', 'cashback')
            ->where('cashback.entries.1.effect', 'credit')
            ->where('cashback.entries.1.amount', ['amountMinor' => '5000', 'currency' => 'SAR'])
            ->where('cashback.entries.1.order.number', 'UT-12345678')
            ->where('cashback.entries.1.order.url', '/en/my-account/orders/'.$order->public_id));
});
