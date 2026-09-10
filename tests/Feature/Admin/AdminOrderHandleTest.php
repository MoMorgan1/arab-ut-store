<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\User;

test('a legacy ULID admin order URL is permanently redirected to the order number', function (string $prefix): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $order = Order::factory()->for(User::factory()->create())->create([
        'order_number' => 'AUT-3043',
        'status' => OrderStatus::Received,
    ]);

    $this->actingAs($admin)
        ->get("{$prefix}/orders/{$order->public_id}")
        ->assertStatus(301)
        ->assertRedirect("{$prefix}/orders/AUT-3043");
})->with([
    'default prefix' => ['/admin'],
    'localized prefix' => ['/en/admin'],
]);

test('the admin order detail resolves by the order number', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $order = Order::factory()->for(User::factory()->create())->create([
        'order_number' => 'AUT-3044',
        'status' => OrderStatus::Received,
    ]);

    $this->actingAs($admin)
        ->get('/admin/orders/AUT-3044')
        ->assertOk();
});

test('an unknown order handle returns 404', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get('/admin/orders/AUT-9999')
        ->assertNotFound();
});

test('a non-staff account cannot follow a legacy ULID admin order URL', function (): void {
    $order = Order::factory()->for(User::factory()->create())->create([
        'order_number' => 'AUT-3045',
        'status' => OrderStatus::Received,
    ]);
    $account = User::factory()->create(['role' => UserRole::Customer]);

    $this->actingAs($account)
        ->get('/admin/orders/'.$order->public_id)
        ->assertForbidden();
});

test('a legacy ULID admin order URL keeps its query string through the redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $order = Order::factory()->for(User::factory()->create())->create([
        'order_number' => 'AUT-3046',
        'status' => OrderStatus::Received,
    ]);

    $this->actingAs($admin)
        ->get("/admin/orders/{$order->public_id}?tab=audit")
        ->assertStatus(301)
        ->assertRedirect('/admin/orders/AUT-3046?tab=audit');
});

test('a lowercase legacy ULID is normalized before the admin redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $order = Order::factory()->for(User::factory()->create())->create([
        'order_number' => 'AUT-3047',
        'status' => OrderStatus::Received,
    ]);

    $this->actingAs($admin)
        ->get('/admin/orders/'.mb_strtolower((string) $order->public_id))
        ->assertStatus(301)
        ->assertRedirect('/admin/orders/AUT-3047');
});

test('imported and legacy order number handles open the admin detail page', function (string $number): void {
    $admin = createStaffTestActor(UserRole::Admin);
    Order::factory()->for(User::factory()->create())->create([
        'order_number' => $number,
        'status' => OrderStatus::Received,
    ]);

    $this->actingAs($admin)
        ->get('/admin/orders/'.$number)
        ->assertOk();
})->with([
    'imported Salla number' => ['UT-00001234'],
    'legacy random number' => ['AUT-7K4QXM'],
]);

test('an admin transition resolves a legacy ULID without a canonicalizing redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $order = Order::factory()->for(User::factory()->create())->create([
        'order_number' => 'AUT-3048',
        'status' => OrderStatus::Received,
    ]);
    adminOrderHandleItem($order, OrderItemStatus::Received);

    $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'received',
            'target_status' => 'in_progress',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'in_progress');

    expect($order->fresh()->status)->toBe(OrderStatus::InProgress);
});

test('an admin secret reveal resolves a legacy ULID without a canonicalizing redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    [$order, $item] = adminOrderHandleOrderWithSecret('AUT-3049');

    $this->actingAs($admin)
        ->postJson("/admin/api/orders/{$order->public_id}/items/{$item->public_id}/reveal", [
            'purpose' => 'fulfillment',
        ])
        ->assertOk()
        ->assertJsonPath('data.ea_email', 'player@example.com');
});

test('an admin refund resolves a legacy ULID and reaches its business validation, not a redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $order = Order::factory()->for(User::factory()->create())->create([
        'order_number' => 'AUT-3050',
        'status' => OrderStatus::Received,
        'total_halalah' => 2500,
        'payment_halalah' => 2500,
    ]);

    // The amount is deliberately short of the order total, so the request has
    // to resolve the order by its legacy ULID and then be refused by the
    // full-refund rule (422) - never a 301 that would have dropped the body.
    $this->actingAs($admin)
        ->postJson("/admin/api/orders/{$order->public_id}/refund", [
            'amountHalalah' => 1000,
            'reason' => 'Partial refund attempt.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'full_refund_required');
});

function adminOrderHandleItem(Order $order, OrderItemStatus $status): OrderItem
{
    return $order->items()->create([
        'sku' => 'AUT-HANDLE-SKU-'.mb_strtoupper(bin2hex(random_bytes(3))),
        'name_ar' => 'خدمة',
        'name_en' => 'Service',
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => $status,
        'quantity' => 1,
        'unit_price_halalah' => 5000,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'total_halalah' => 5000,
    ]);
}

/** @return array{0: Order, 1: OrderItem} */
function adminOrderHandleOrderWithSecret(string $number): array
{
    $order = Order::factory()->for(User::factory()->create(['role' => UserRole::Customer]))->create([
        'order_number' => $number,
        'status' => OrderStatus::InProgress,
    ]);

    $item = adminOrderHandleItem($order, OrderItemStatus::InProgress);

    $secret = new OrderItemSecret([
        'order_item_id' => $item->id,
        'masked_summary' => ['account' => 'p***r@example.com'],
    ]);
    $secret->forceFill([
        'encrypted_payload' => [
            'ea_email' => 'player@example.com',
            'ea_password' => 'SecretPassword123!',
            'ea_backup_codes' => ['11111111', '22222222'],
        ],
    ])->save();

    return [$order, $item];
}
