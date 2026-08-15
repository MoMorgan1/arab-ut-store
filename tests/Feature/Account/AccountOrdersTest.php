<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;

function ordersTestOrder(
    User $user,
    int $sequence,
    OrderStatus $status = OrderStatus::InProgress,
): Order {
    $order = Order::factory()->for($user)->create([
        'order_number' => sprintf('UT-%08d', $sequence),
        'status' => $status,
        'placed_at' => now()->subDays($sequence),
        'subtotal_halalah' => $sequence * 1_000,
        'payment_halalah' => $sequence * 1_000,
        'total_halalah' => $sequence * 1_000,
    ]);

    OrderItem::factory()->for($order)->create([
        'name_ar' => "خدمة {$sequence}",
        'name_en' => "Service {$sequence}",
        'status' => match ($status) {
            OrderStatus::Completed => OrderItemStatus::Completed,
            OrderStatus::WaitingForCustomer => OrderItemStatus::WaitingForCustomer,
            default => OrderItemStatus::InProgress,
        },
        'configuration' => ['ea_password' => 'orders-page-must-not-serialize-this'],
    ]);

    return $order;
}

test('the bilingual orders destinations render owner-scoped bounded pagination', function (
    string $path,
    string $locale,
): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    foreach (range(1, 11) as $sequence) {
        ordersTestOrder($owner, $sequence);
    }

    ordersTestOrder($other, 99, OrderStatus::Completed);

    $response = $this->actingAs($owner)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page
            ->component('account/orders')
            ->where('locale', $locale)
            ->where('filters.status', 'all')
            ->where('pagination.currentPage', 1)
            ->where('pagination.lastPage', 2)
            ->where('pagination.perPage', 10)
            ->where('pagination.total', 11)
            ->has('orders', 10)
            ->where('orders', fn ($orders): bool => collect($orders)
                ->every(fn (array $order): bool => $order['number'] !== 'UT-00000099'))
            ->where('accountNavigation', fn ($items): bool => collect($items)->pluck('key')->all() === [
                'overview',
                'orders',
                'wallet',
            ]));

    expect($response->inertiaPage()['encryptHistory'] ?? false)->toBeTrue();
})->with([
    'Arabic orders' => ['/my-account/orders', 'ar'],
    'English orders' => ['/en/my-account/orders', 'en'],
]);

test('order status filters are allowlisted and preserve canonical pagination URLs', function (): void {
    $user = User::factory()->create();
    ordersTestOrder($user, 1, OrderStatus::Completed);
    ordersTestOrder($user, 2, OrderStatus::WaitingForCustomer);

    $this->actingAs($user)
        ->get('/my-account/orders?status=completed')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.status', 'completed')
            ->has('orders', 1)
            ->where('orders.0.status', 'completed')
            ->where('pagination.nextUrl', null)
            ->where('pagination.previousUrl', null));

    $this->get('/my-account/orders?status=internal_review')
        ->assertRedirect()
        ->assertSessionHasErrors('status');
});

test('live order detail exposes current safe item progress and payment recovery only to its owner', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $order = ordersTestOrder($owner, 7, OrderStatus::PendingPayment);
    $item = $order->items()->sole();
    $item->secret()->forceCreate([
        'encrypted_payload' => ['password' => 'detail-page-must-not-serialize-this'],
        'masked_summary' => ['account' => 'm***d'],
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->public_id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('account/live-order')
            ->where('locale', 'en')
            ->where('order.id', $order->public_id)
            ->where('order.number', 'UT-00000007')
            ->where('order.status', 'pending_payment')
            ->where('order.total', ['amountMinor' => '7000', 'currency' => 'SAR'])
            ->where('order.refreshable', true)
            ->where('order.paymentStartUrl', '/en/orders/'.$order->public_id.'/payments/paylink')
            ->has('order.items', 1)
            ->where('order.items.0.credentialsPresent', true)
            ->where('order.items.0.name', 'Service 7')
            ->missing('order.items.0.configuration')
            ->missing('order.items.0.credentials')
            ->missing('order.payments'));

    $payload = json_encode($response->inertiaPage(), JSON_THROW_ON_ERROR);

    expect($payload)
        ->not->toContain('orders-page-must-not-serialize-this')
        ->not->toContain('detail-page-must-not-serialize-this')
        ->not->toContain('masked_summary');

    $this->actingAs($other)
        ->get('/my-account/orders/'.$order->public_id)
        ->assertNotFound();
});

test('terminal live orders cannot expose operational refresh or payment actions', function (): void {
    $user = User::factory()->create();
    $order = ordersTestOrder($user, 8, OrderStatus::Completed);

    $this->actingAs($user)
        ->get('/my-account/orders/'.$order->public_id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.refreshable', false)
            ->where('order.paymentStartUrl', null));
});

test('legacy direct order URLs redirect their owner to the canonical locale', function (): void {
    $owner = User::factory()->create();
    $order = ordersTestOrder($owner, 9);

    $this->actingAs($owner)
        ->get('/orders/'.$order->public_id)
        ->assertRedirect('/my-account/orders/'.$order->public_id);

    $this->actingAs($owner)
        ->get('/en/orders/'.$order->public_id)
        ->assertRedirect('/en/my-account/orders/'.$order->public_id);
});

test('legacy direct order URLs do not reveal another customers order', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $order = ordersTestOrder($owner, 10);

    $this->actingAs($other)
        ->get('/orders/'.$order->public_id)
        ->assertNotFound();
});
