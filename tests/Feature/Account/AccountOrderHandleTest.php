<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\User;

function accountOrderHandleOrder(User $user, string $number): Order
{
    $order = Order::factory()->for($user)->create([
        'order_number' => $number,
        'status' => OrderStatus::PendingPayment,
    ]);

    OrderItem::factory()->for($order)->create();

    return $order;
}

test('a legacy ULID account order URL is permanently redirected to the order number', function (string $prefix): void {
    $owner = User::factory()->create();
    $order = accountOrderHandleOrder($owner, 'AUT-2043');

    $this->actingAs($owner)
        ->get("{$prefix}/my-account/orders/{$order->public_id}")
        ->assertStatus(301)
        ->assertRedirect("{$prefix}/my-account/orders/AUT-2043");
})->with([
    'Arabic' => [''],
    'English' => ['/en'],
]);

test('a legacy ULID account order URL keeps its query string through the redirect', function (): void {
    $owner = User::factory()->create();
    $order = accountOrderHandleOrder($owner, 'AUT-2044');

    $this->actingAs($owner)
        ->get("/my-account/orders/{$order->public_id}?step=review")
        ->assertStatus(301)
        ->assertRedirect('/my-account/orders/AUT-2044?step=review');
});

test('the account order detail resolves by order number and redirects a legacy ULID', function (): void {
    $owner = User::factory()->create();
    $order = accountOrderHandleOrder($owner, 'AUT-2045');

    $this->actingAs($owner)
        ->get('/my-account/orders/AUT-2045')
        ->assertOk();

    $this->actingAs($owner)
        ->get('/my-account/orders/'.$order->public_id)
        ->assertStatus(301)
        ->assertRedirect('/my-account/orders/AUT-2045');
});

test('another customer cannot resolve an order through its legacy ULID', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $order = accountOrderHandleOrder($owner, 'AUT-2046');

    $this->actingAs($other)
        ->get('/my-account/orders/'.$order->public_id)
        ->assertNotFound();
});

test('a legacy direct order URL redirects to the canonical number URL', function (): void {
    $owner = User::factory()->create();
    $order = accountOrderHandleOrder($owner, 'AUT-2047');

    $this->actingAs($owner)
        ->get('/orders/'.$order->public_id)
        ->assertRedirect('/my-account/orders/AUT-2047');
});

test('generated account order URLs never address the order by its internal ULID', function (): void {
    $owner = User::factory()->create();
    $order = accountOrderHandleOrder($owner, 'AUT-2048');

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/AUT-2048')
        ->assertOk();

    $liveOrder = $response->inertiaPage()['props']['order'];

    expect($liveOrder['paymentStartUrl'])
        ->toBe('/en/orders/AUT-2048/payments/paylink')
        ->and($liveOrder['cancelUrl'])
        ->toBe('/en/my-account/orders/AUT-2048/cancel')
        ->and($liveOrder['paymentStartUrl'].$liveOrder['cancelUrl'])
        ->not->toContain((string) $order->public_id);
});

test('a lowercase legacy ULID account order URL is normalized before the redirect', function (): void {
    $owner = User::factory()->create();
    $order = accountOrderHandleOrder($owner, 'AUT-2049');

    $this->actingAs($owner)
        ->get('/my-account/orders/'.mb_strtolower((string) $order->public_id))
        ->assertStatus(301)
        ->assertRedirect('/my-account/orders/AUT-2049');
});

test('an unknown order number handle returns 404 on the customer order page', function (): void {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get('/my-account/orders/AUT-9999')
        ->assertNotFound();

    $this->actingAs($owner)
        ->get('/my-account/orders/UT-99999999')
        ->assertNotFound();
});

test('imported and legacy order number handles open the account order page', function (string $number): void {
    $owner = User::factory()->create();
    accountOrderHandleOrder($owner, $number);

    $this->actingAs($owner)
        ->get('/my-account/orders/'.$number)
        ->assertOk();
})->with([
    'imported Salla number' => ['UT-00001234'],
    'legacy random number' => ['AUT-7K4QXM'],
]);

test('the owner can cancel through a legacy ULID without a canonicalizing redirect', function (): void {
    $owner = User::factory()->create();
    $order = accountOrderHandleOrder($owner, 'AUT-2050');

    $this->actingAs($owner)
        ->from('/my-account/orders/AUT-2050')
        ->post("/my-account/orders/{$order->public_id}/cancel")
        ->assertStatus(302)
        ->assertRedirect('/my-account/orders/AUT-2050')
        ->assertSessionHas('status', 'order-cancelled');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

test('the owner can submit a review through a legacy ULID without a canonicalizing redirect', function (): void {
    $owner = User::factory()->create();
    $order = Order::factory()->for($owner)->create([
        'order_number' => 'AUT-2051',
        'status' => OrderStatus::Completed,
        'completed_at' => now()->subDay(),
        'channel' => 'store',
        'locale' => 'ar',
    ]);

    $this->actingAs($owner)
        ->postJson("/my-account/orders/{$order->public_id}/review", [
            'rating' => 5,
            'body' => 'خدمة سريعة.',
        ])
        ->assertOk();

    expect(Review::query()->where('order_id', $order->id)->exists())->toBeTrue();
});
