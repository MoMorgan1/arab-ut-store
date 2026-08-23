<?php

require_once __DIR__.'/LoyaltyFixtures.php';

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Loyalty\Actions\AccrueOrderCashback;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;

beforeEach(function (): void {
    config()->set('store.features.loyalty_enabled', true);
    loyaltySeedTiers();
});

test('cashback rounds down to whole halalah from the paid basis', function (): void {
    $user = User::factory()->create();
    $order = loyaltyPaidOrder($user, ['total_halalah' => 49_900, 'payment_halalah' => 49_900]);

    $entry = app(AccrueOrderCashback::class)->execute($order->fresh());

    expect($entry)->toBeInstanceOf(WalletEntry::class)
        ->and($entry->amount_halalah)->toBe(998)
        ->and($entry->balance_after_halalah)->toBe(998)
        ->and($entry->type->value)->toBe('cashback')
        ->and($entry->reference)->toBe("cashback:{$order->id}")
        ->and($entry->metadata['tier_key'])->toBe('bronze');
});

test('the tier applied is the one reached excluding the order being completed', function (): void {
    $user = User::factory()->create();
    loyaltyPaidOrder($user, ['total_halalah' => 60_000, 'payment_halalah' => 60_000]);
    $order = loyaltyPaidOrder($user, ['total_halalah' => 1_000_000, 'payment_halalah' => 1_000_000]);

    $entry = app(AccrueOrderCashback::class)->execute($order->fresh());

    expect($entry)->toBeInstanceOf(WalletEntry::class)
        ->and($entry->amount_halalah)->toBe(30_000)
        ->and($entry->metadata['tier_key'])->toBe('silver')
        ->and($entry->metadata['basis_halalah'])->toBe(1_000_000);
});

test('wallet-funded portions are excluded from the cashback basis', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->create([
        'status' => OrderStatus::Completed,
        'completed_at' => now(),
        'wallet_halalah' => 5_000,
        'payment_halalah' => 5_000,
        'total_halalah' => 10_000,
    ]);
    loyaltySettledPayment($order, PaymentStatus::Paid, 5_000);

    $entry = app(AccrueOrderCashback::class)->execute($order);

    expect($entry)->toBeInstanceOf(WalletEntry::class)
        ->and($entry->amount_halalah)->toBe(100)
        ->and($entry->metadata['basis_halalah'])->toBe(5_000);
});

test('a zero cashback amount skips the ledger entirely', function (): void {
    $user = User::factory()->create();
    $order = loyaltyPaidOrder($user, ['total_halalah' => 40, 'payment_halalah' => 40]);

    $entry = app(AccrueOrderCashback::class)->execute($order);

    expect($entry)->toBeNull()
        ->and(WalletEntry::query()->count())->toBe(0)
        ->and(WalletAccount::query()->count())->toBe(0);
});

test('non-SAR orders are skipped', function (): void {
    $user = User::factory()->create();
    $order = loyaltyPaidOrder($user, [
        'currency' => 'USD',
        'total_halalah' => 50_000,
        'payment_halalah' => 50_000,
    ]);

    expect(app(AccrueOrderCashback::class)->execute($order))->toBeNull()
        ->and(WalletEntry::query()->count())->toBe(0);
});

test('orders that are not fully paid are skipped', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->create([
        'status' => OrderStatus::Completed,
        'completed_at' => now(),
        'payment_halalah' => 50_000,
        'total_halalah' => 50_000,
    ]);
    loyaltySettledPayment($order, PaymentStatus::Pending, 0);

    expect(app(AccrueOrderCashback::class)->execute($order))->toBeNull()
        ->and(WalletEntry::query()->count())->toBe(0);
});

test('orders without a completion timestamp are skipped', function (): void {
    $user = User::factory()->create();
    $order = loyaltyPaidOrder($user, ['completed_at' => null]);

    expect(app(AccrueOrderCashback::class)->execute($order))->toBeNull()
        ->and(WalletEntry::query()->count())->toBe(0);
});

test('the feature flag disables accrual without side effects', function (): void {
    config()->set('store.features.loyalty_enabled', false);
    $user = User::factory()->create();
    $order = loyaltyPaidOrder($user);

    expect(app(AccrueOrderCashback::class)->execute($order))->toBeNull()
        ->and(WalletEntry::query()->count())->toBe(0)
        ->and(WalletAccount::query()->count())->toBe(0);
});

test('a second call returns the existing entry and leaves the balance untouched', function (): void {
    $user = User::factory()->create();
    $order = loyaltyPaidOrder($user);
    $action = app(AccrueOrderCashback::class);

    $first = $action->execute($order);
    $second = $action->execute($order->fresh());

    expect($first)->toBeInstanceOf(WalletEntry::class)
        ->and($second?->id)->toBe($first?->id)
        ->and($second?->amount_halalah)->toBe(200)
        ->and(WalletEntry::query()->where('reference', "cashback:{$order->id}")->count())->toBe(1)
        ->and(WalletAccount::query()->sole()->balance_halalah)->toBe(200);
});
