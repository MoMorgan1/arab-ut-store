<?php

use App\Account\Queries\ResolveLiveActionableOrder;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\LoyaltyTier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Testing\TestResponse;

function accountOrder(
    User $user,
    OrderStatus $status,
    int $totalHalalah,
    string $placedAt,
    array $attributes = [],
): Order {
    return Order::factory()->for($user)->create([
        'status' => $status,
        'subtotal_halalah' => $totalHalalah,
        'payment_halalah' => $totalHalalah,
        'total_halalah' => $totalHalalah,
        'placed_at' => $placedAt,
        ...$attributes,
    ]);
}

function accountOrderItem(Order $order, string $nameAr, string $nameEn): OrderItem
{
    return OrderItem::factory()->for($order)->create([
        'name_ar' => $nameAr,
        'name_en' => $nameEn,
        'status' => OrderItemStatus::InProgress,
        'configuration' => [
            'ea_password' => 'never-serialize-this-password',
            'internal_note' => 'never-serialize-this-note',
        ],
    ]);
}

function accountPayment(Order $order, PaymentStatus $status, int $capturedHalalah): void
{
    $order->payments()->create([
        'provider' => 'paylink',
        'provider_payment_id' => (string) str()->ulid(),
        'status' => $status,
        'currency' => 'SAR',
        'amount_halalah' => $order->payment_halalah,
        'captured_halalah' => $capturedHalalah,
        'refunded_halalah' => 0,
        'idempotency_key' => (string) str()->ulid(),
        'provider_metadata' => ['provider_secret' => 'never-serialize-provider-secret'],
    ]);
}

function accountOverview(User $user, string $path = '/my-account'): TestResponse
{
    return test()->actingAs($user)->get($path)->assertOk();
}

test('a new customer receives an honest empty current-data overview', function (): void {
    $user = User::factory()->create([
        'first_name' => 'محمد',
        'last_name' => 'لاعب',
    ]);

    accountOverview($user)
        ->assertInertia(fn ($page) => $page
            ->where('accountIdentity', [
                'name' => 'محمد لاعب',
                'greeting' => 'مرحبًا، محمد لاعب',
            ])
            ->where('accountNavigation', [
                ['key' => 'overview', 'label' => 'نظرة عامة', 'url' => '/my-account'],
                ['key' => 'orders', 'label' => 'طلباتي', 'url' => '/my-account/orders'],
                ['key' => 'wallet', 'label' => 'محفظتي', 'url' => '/my-account/wallet'],
                ['key' => 'profile', 'label' => 'بياناتي', 'url' => '/my-account/profile'],
                ['key' => 'security', 'label' => 'الأمان', 'url' => '/my-account/security'],
                ['key' => 'support', 'label' => 'الدعم', 'url' => '/my-account/support'],
            ])
            ->where('logoutUrl', '/logout')
            ->where('summary.orderCount', 0)
            ->where('summary.openOrderCount', 0)
            ->where('summary.completedOrderCount', 0)
            ->where('summary.walletBalance', null)
            ->where('activeOrder', null)
            ->where('recentOrders', [])
            ->where('loyalty', null));
});

test('the overview scopes metrics and the three newest localized orders to the owner', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    WalletAccount::factory()->for($owner)->create(['balance_halalah' => 98_765]);
    WalletAccount::factory()->for($other)->create(['balance_halalah' => 999_999]);

    foreach ([1, 2, 3, 4] as $day) {
        $order = accountOrder(
            $owner,
            $day === 1 ? OrderStatus::Completed : OrderStatus::InProgress,
            $day * 1_000,
            "2026-08-0{$day} 12:00:00",
        );
        accountOrderItem($order, "خدمة {$day}", "Service {$day}");
    }

    $otherOrder = accountOrder($other, OrderStatus::Completed, 900_000, '2026-08-10 12:00:00');
    accountOrderItem($otherOrder, 'طلب مستخدم آخر', 'Another customer order');

    accountOverview($owner, '/en/my-account')
        ->assertInertia(fn ($page) => $page
            ->where('summary.orderCount', 4)
            ->where('summary.openOrderCount', 3)
            ->where('summary.completedOrderCount', 1)
            ->where('summary.walletBalance', [
                'amountMinor' => '98765',
                'currency' => 'SAR',
            ])
            ->has('recentOrders', 3)
            ->where('recentOrders.0.summary', 'Service 4')
            ->where('recentOrders.1.summary', 'Service 3')
            ->where('recentOrders.2.summary', 'Service 2')
            ->where('recentOrders.0.source', 'live')
            ->where('recentOrders.0.placedAt', fn (string $date): bool => str_starts_with($date, '2026-08-04T12:00:00'))
            ->where('recentOrders.0.detailUrl', fn (string $url): bool => str_starts_with($url, '/en/my-account/orders/'))
            ->where('recentOrders', fn ($orders): bool => collect($orders)
                ->every(fn (array $order): bool => $order['total']['amountMinor'] !== '900000')));
});

test('the actionable live order follows customer recovery priority', function (): void {
    $user = User::factory()->create();

    $inProgress = accountOrder($user, OrderStatus::InProgress, 1_000, '2026-08-14 12:00:00');
    accountOrderItem($inProgress, 'قيد التنفيذ', 'In progress');

    $pending = accountOrder($user, OrderStatus::PendingPayment, 2_000, '2026-08-13 12:00:00');
    accountOrderItem($pending, 'بانتظار الدفع', 'Awaiting payment');

    $failedPayment = accountOrder($user, OrderStatus::PendingPayment, 3_000, '2026-08-12 12:00:00');
    accountOrderItem($failedPayment, 'دفع متعثر', 'Failed payment');
    accountPayment($failedPayment, PaymentStatus::Failed, 0);

    $waiting = accountOrder($user, OrderStatus::WaitingForCustomer, 4_000, '2026-08-11 12:00:00');
    accountOrderItem($waiting, 'بانتظار العميل', 'Waiting for customer');

    $query = app(ResolveLiveActionableOrder::class);

    expect($query->for($user, 'ar'))
        ->toHaveKey('id', $waiting->public_id)
        ->toHaveKey('action.type', 'provide_details');

    $waiting->update(['status' => OrderStatus::Completed]);

    expect($query->for($user, 'ar'))
        ->toHaveKey('id', $failedPayment->public_id)
        ->toHaveKey('action.type', 'retry_payment');
});

test('loyalty uses net settled completed SAR spend including wallet funding', function (): void {
    $user = User::factory()->create();

    LoyaltyTier::query()->create([
        'key' => 'bronze',
        'name_ar' => 'برونزي',
        'name_en' => 'Bronze',
        'rank' => 1,
        'minimum_lifetime_spend_halalah' => 0,
        'is_active' => true,
    ]);
    LoyaltyTier::query()->create([
        'key' => 'gold',
        'name_ar' => 'ذهبي',
        'name_en' => 'Gold',
        'rank' => 2,
        'minimum_lifetime_spend_halalah' => 20_000,
        'is_active' => true,
    ]);

    $gatewayOrder = accountOrder($user, OrderStatus::Completed, 15_000, '2026-08-01 12:00:00', [
        'completed_at' => '2026-08-01 13:00:00',
    ]);
    accountPayment($gatewayOrder, PaymentStatus::PartiallyRefunded, 15_000);
    $gatewayOrder->refunds()->create([
        'method' => 'paylink',
        'status' => 'completed',
        'amount_halalah' => 2_000,
        'completed_at' => '2026-08-02 12:00:00',
    ]);
    $gatewayOrder->refunds()->create([
        'method' => 'paylink',
        'status' => 'failed',
        'amount_halalah' => 9_000,
    ]);

    accountOrder($user, OrderStatus::Completed, 5_000, '2026-08-03 12:00:00', [
        'wallet_halalah' => 5_000,
        'payment_halalah' => 0,
        'completed_at' => '2026-08-03 13:00:00',
    ]);
    $overRefunded = accountOrder($user, OrderStatus::Refunded, 1_000, '2026-08-03 14:00:00', [
        'wallet_halalah' => 1_000,
        'payment_halalah' => 0,
        'completed_at' => '2026-08-03 14:30:00',
    ]);
    $overRefunded->refunds()->create([
        'method' => 'wallet',
        'status' => 'completed',
        'amount_halalah' => 2_000,
        'completed_at' => '2026-08-03 15:00:00',
    ]);
    $pending = accountOrder($user, OrderStatus::PendingPayment, 50_000, '2026-08-04 12:00:00');
    accountPayment($pending, PaymentStatus::Paid, 50_000);
    $unsettled = accountOrder($user, OrderStatus::Completed, 70_000, '2026-08-05 12:00:00', [
        'completed_at' => '2026-08-05 13:00:00',
    ]);
    accountPayment($unsettled, PaymentStatus::Failed, 0);
    $usd = accountOrder($user, OrderStatus::Completed, 80_000, '2026-08-06 12:00:00', [
        'currency' => 'USD',
        'completed_at' => '2026-08-06 13:00:00',
    ]);
    accountPayment($usd, PaymentStatus::Paid, 80_000);

    accountOverview($user, '/en/my-account')
        ->assertInertia(fn ($page) => $page
            ->where('loyalty.eligibleSpend', ['amountMinor' => '18000', 'currency' => 'SAR'])
            ->where('loyalty.currentTier.key', 'bronze')
            ->where('loyalty.currentTier.name', 'Bronze')
            ->where('loyalty.nextTier.key', 'gold')
            ->where('loyalty.remaining', ['amountMinor' => '2000', 'currency' => 'SAR'])
            ->where('loyalty.progressPercent', 90));
});

test('account projections do not expose order secrets or provider payloads', function (): void {
    $user = User::factory()->create();
    $order = accountOrder($user, OrderStatus::InProgress, 12_000, '2026-08-15 12:00:00');
    accountOrderItem($order, 'خدمة آمنة', 'Safe service');
    accountPayment($order, PaymentStatus::Paid, 12_000);

    $payload = json_encode(accountOverview($user)->inertiaPage(), JSON_THROW_ON_ERROR);

    expect($payload)
        ->not->toContain('never-serialize-this-password')
        ->not->toContain('never-serialize-this-note')
        ->not->toContain('never-serialize-provider-secret')
        ->not->toContain('configuration')
        ->not->toContain('provider_metadata');
});
