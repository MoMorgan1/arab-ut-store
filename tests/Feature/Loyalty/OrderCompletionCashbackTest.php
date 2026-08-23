<?php

require_once __DIR__.'/LoyaltyFixtures.php';

use App\Admin\Actions\TransitionAdminOrder;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Loyalty\Actions\AccrueOrderCashback;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;

beforeEach(function (): void {
    config()->set('store.features.loyalty_enabled', true);
    loyaltySeedTiers();
});

function loyaltyAdminActor(): User
{
    return User::factory()->create(['role' => UserRole::Admin]);
}

function loyaltyTransitionOrder(User $customer): Order
{
    $order = Order::factory()->for($customer)->create([
        'status' => OrderStatus::Received,
        'payment_halalah' => 20_000,
        'total_halalah' => 20_000,
        'paid_at' => now(),
    ]);
    $order->items()->create([
        'sku' => 'AUT-LOYALTY-1',
        'name_ar' => 'عملة',
        'name_en' => 'Coins',
        'service_type' => 'coins',
        'platform' => 'playstation',
        'status' => OrderItemStatus::Received,
        'quantity' => 1,
        'unit_price_halalah' => 20_000,
        'subtotal_halalah' => 20_000,
        'discount_halalah' => 0,
        'total_halalah' => 20_000,
    ]);
    loyaltySettledPayment($order, PaymentStatus::Paid, 20_000);

    return $order;
}

test('completing an order inside the admin transition accrues one cashback entry and updates the balance', function (): void {
    $customer = User::factory()->create();
    $admin = loyaltyAdminActor();
    $order = loyaltyTransitionOrder($customer);

    app(TransitionAdminOrder::class)->execute($admin, (string) $order->public_id, OrderStatus::Completed, OrderStatus::Received);

    $entry = WalletEntry::query()->where('reference', "cashback:{$order->id}")->sole();

    expect($entry->type->value)->toBe('cashback')
        ->and($entry->amount_halalah)->toBe(400)
        ->and($entry->balance_after_halalah)->toBe(400)
        ->and((int) WalletAccount::query()->where('user_id', $customer->id)->value('balance_halalah'))->toBe(400);
});

test('a replayed completion accrual does not duplicate entries or change the balance', function (): void {
    $customer = User::factory()->create();
    $admin = loyaltyAdminActor();
    $order = loyaltyTransitionOrder($customer);

    app(TransitionAdminOrder::class)->execute($admin, (string) $order->public_id, OrderStatus::Completed, OrderStatus::Received);

    $accrual = app(AccrueOrderCashback::class);
    $replayed = $accrual->execute($order->fresh());
    $entry = WalletEntry::query()->sole();

    expect($replayed?->id)->toBe($entry->id)
        ->and(WalletEntry::query()->count())->toBe(1)
        ->and(WalletAccount::query()->sole()->balance_halalah)->toBe(400);
});

test('non-completed transitions never accrue cashback', function (): void {
    $customer = User::factory()->create();
    $admin = loyaltyAdminActor();
    $order = loyaltyTransitionOrder($customer);

    app(TransitionAdminOrder::class)->execute($admin, (string) $order->public_id, OrderStatus::InProgress, OrderStatus::Received);

    expect(WalletEntry::query()->count())->toBe(0)
        ->and(WalletAccount::query()->count())->toBe(0);
});

test('with the flag off, completing an order writes no ledger rows', function (): void {
    config()->set('store.features.loyalty_enabled', false);
    $customer = User::factory()->create();
    $admin = loyaltyAdminActor();
    $order = loyaltyTransitionOrder($customer);

    app(TransitionAdminOrder::class)->execute($admin, (string) $order->public_id, OrderStatus::Completed, OrderStatus::Received);

    expect(WalletEntry::query()->count())->toBe(0)
        ->and(WalletAccount::query()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Completed);
});
