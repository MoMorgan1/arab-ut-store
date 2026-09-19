<?php

use App\Enums\OrderStatus;
use App\Loyalty\Support\EligibleOrderSpend;
use App\Models\Order;
use App\Models\User;

function importedOrderFor(User $user, int $totalHalalah, string $channel): Order
{
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'status' => OrderStatus::Completed,
        'currency' => 'SAR',
        'total_halalah' => $totalHalalah,
        'wallet_halalah' => 0,
    ]);

    $order->forceFill([
        'channel' => $channel,
        'completed_at' => now()->subMonth(),
        'paid_at' => now()->subMonth(),
    ])->save();

    return $order;
}

test('an imported Salla order counts toward lifetime spend even though it has no payment rows', function (): void {
    $user = User::factory()->create();
    importedOrderFor($user, 200_000, 'salla_import');

    // The money was taken on the old platform, so there is no payment row and no
    // wallet usage here. Without the import special-case the coverage check
    // scores this zero, and a customer who spent 2,000 SAR lands in the bottom
    // tier the day we migrate - which is the opposite of the intent.
    expect(app(EligibleOrderSpend::class)->lifetime($user->id))->toBe(200_000);
});

test('a store order with no settled payment still counts for nothing', function (): void {
    $user = User::factory()->create();
    importedOrderFor($user, 200_000, 'store');

    // The special-case must be narrow: an ordinary order that was never paid
    // for cannot buy a tier.
    expect(app(EligibleOrderSpend::class)->lifetime($user->id))->toBe(0);
});

test('imported orders are still never treated as settled, so they cannot accrue cashback', function (): void {
    $user = User::factory()->create();
    $order = importedOrderFor($user, 200_000, 'salla_import');

    // Counting toward a tier and being settled are different things: settlement
    // is what triggers accrual, and paying cashback on history the store already
    // fulfilled elsewhere would mint real money.
    expect(app(EligibleOrderSpend::class)->fullySettled($order))->toBeFalse();
});

// Owner, 2026-09-19: a manual order earns no cashback and no tier. It is
// staff writing an order the store did not sell - a bank transfer taken
// outside the gateway, or a gift - and counting it would let anybody holding
// `orders.create` raise a customer's rate on every future order.

test('a manual order adds nothing to lifetime spend, however it was paid', function (): void {
    $user = User::factory()->create();
    $order = importedOrderFor($user, 200_000, 'manual');

    // Fully covered in wallet terms, so it is not the coverage check turning
    // it away - the channel is.
    $order->forceFill(['wallet_halalah' => 200_000])->save();

    expect(app(EligibleOrderSpend::class)->lifetime($user->id))->toBe(0);
});

test('a manual order is never settled, so it cannot accrue cashback', function (): void {
    $user = User::factory()->create();
    $order = importedOrderFor($user, 200_000, 'manual');
    $order->forceFill(['wallet_halalah' => 200_000])->save();

    expect(app(EligibleOrderSpend::class)->fullySettled($order))->toBeFalse();
});

test('a manual order does not drag down a customer who also bought normally', function (): void {
    $user = User::factory()->create();
    $store = importedOrderFor($user, 200_000, 'store');
    $store->forceFill(['wallet_halalah' => 200_000])->save();
    $manual = importedOrderFor($user, 500_000, 'manual');
    $manual->forceFill(['wallet_halalah' => 500_000])->save();

    // The exclusion is per order, not a switch on the whole customer.
    expect(app(EligibleOrderSpend::class)->lifetime($user->id))->toBe(200_000);
});
