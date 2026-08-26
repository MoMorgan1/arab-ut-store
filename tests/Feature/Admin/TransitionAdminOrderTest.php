<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('admin can transition received order to in_progress with item propagation, history, and staff audit', function (): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::Received);

    $receivedItem = $order->items()->create([
        'sku' => 'AUT-ITEM-REC',
        'name_ar' => 'عنصر مستلم',
        'name_en' => 'Received item',
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::Received,
        'quantity' => 1,
        'unit_price_halalah' => 5000,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'total_halalah' => 5000,
    ]);

    $cancelledItem = $order->items()->create([
        'sku' => 'AUT-ITEM-CANCELLED',
        'name_ar' => 'عنصر ملغي',
        'name_en' => 'Cancelled item',
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::Cancelled,
        'quantity' => 1,
        'unit_price_halalah' => 5000,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'total_halalah' => 5000,
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'received',
            'target_status' => 'in_progress',
        ]);

    $response->assertOk()
        ->assertJson([
            'order' => [
                'id' => (string) $order->public_id,
                'status' => 'in_progress',
            ],
            'status' => 'in_progress',
        ]);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::InProgress);

    // The received item moves; an item already out of the flow stays put.
    expect($receivedItem->fresh()->status)->toBe(OrderItemStatus::InProgress)
        ->and($cancelledItem->fresh()->status)->toBe(OrderItemStatus::Cancelled);

    // Order status history created: 1 for order, 1 for propagated item
    $history = OrderStatusHistory::query()->where('order_id', $order->id)->get();
    expect($history)->toHaveCount(2);

    $orderHistory = $history->firstWhere('order_item_id', null);
    expect($orderHistory)->not->toBeNull()
        ->and($orderHistory->status)->toBe(OrderStatusHistoryStatus::InProgress)
        ->and($orderHistory->metadata['source'])->toBe('admin')
        ->and($orderHistory->metadata['previous_status'])->toBe('received')
        ->and($orderHistory->metadata['new_status'])->toBe('in_progress');

    $itemHistory = $history->firstWhere('order_item_id', $receivedItem->id);
    expect($itemHistory)->not->toBeNull()
        ->and($itemHistory->status)->toBe(OrderStatusHistoryStatus::InProgress);

    // Exactly one staff audit row recorded
    $audits = StaffAuditLog::query()
        ->where('auditable_type', $order->getMorphClass())
        ->where('auditable_id', $order->id)
        ->get();

    expect($audits)->toHaveCount(1);
    $audit = $audits->first();
    expect($audit->action)->toBe('orders.status_changed')
        ->and($audit->actor_user_id)->toBe($admin->id)
        ->and(array_keys($audit->metadata))->toBe([
            'source',
            'previous_status',
            'new_status',
            'order_public_id',
            'propagated_item_count',
            'reason',
            'note_given',
        ])
        ->and($audit->metadata)->toBe([
            'source' => 'admin',
            'previous_status' => 'received',
            'new_status' => 'in_progress',
            'order_public_id' => (string) $order->public_id,
            'propagated_item_count' => 1,
            'reason' => null,
            'note_given' => false,
        ]);
});

test('transitioning to completed sets completed_at timestamp', function (): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::InProgress);

    $item = $order->items()->create([
        'sku' => 'AUT-ITEM-COMP',
        'name_ar' => 'عنصر',
        'name_en' => 'Item',
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::InProgress,
        'quantity' => 1,
        'unit_price_halalah' => 5000,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'total_halalah' => 5000,
    ]);

    $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'in_progress',
            'target_status' => 'completed',
        ])
        ->assertOk();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->completed_at)->not->toBeNull()
        ->and($order->cancelled_at)->toBeNull()
        ->and($item->fresh()->status)->toBe(OrderItemStatus::Completed);
});

test('transitioning to cancelled sets cancelled_at timestamp and cancels all active items', function (): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::WaitingForCustomer);

    $waitingItem = $order->items()->create([
        'sku' => 'AUT-ITEM-WAIT',
        'name_ar' => 'عنصر معلق',
        'name_en' => 'Waiting item',
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::WaitingForCustomer,
        'quantity' => 1,
        'unit_price_halalah' => 5000,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'total_halalah' => 5000,
    ]);

    $completedItem = $order->items()->create([
        'sku' => 'AUT-ITEM-DONE',
        'name_ar' => 'عنصر مكتمل',
        'name_en' => 'Completed item',
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::Completed,
        'quantity' => 1,
        'unit_price_halalah' => 5000,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'total_halalah' => 5000,
    ]);

    $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'waiting_for_customer',
            'target_status' => 'cancelled',
        ])
        ->assertOk();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancelled_at)->not->toBeNull()
        ->and($order->completed_at)->toBeNull()
        ->and($waitingItem->fresh()->status)->toBe(OrderItemStatus::Cancelled)
        ->and($completedItem->fresh()->status)->toBe(OrderItemStatus::Completed);
});

test('stale expected_status yields 409 conflict JSON with fresh canonical status and zero writes', function (): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::InProgress);

    $initialHistoryCount = OrderStatusHistory::query()->count();
    $initialAuditCount = StaffAuditLog::query()->count();

    // Client sends stale expected_status 'received', but DB is 'in_progress'
    $response = $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'received',
            'target_status' => 'completed',
        ]);

    $response->assertStatus(409)
        ->assertJson([
            'order' => (string) $order->public_id,
            'status' => 'in_progress',
        ]);

    // Zero writes performed
    expect($order->fresh()->status)->toBe(OrderStatus::InProgress)
        ->and(OrderStatusHistory::query()->count())->toBe($initialHistoryCount)
        ->and(StaffAuditLog::query()->count())->toBe($initialAuditCount);
});

test('illegal transition pairs return 422 validation failure without side effects', function (
    string $fromStatus,
    string $toStatus,
): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::from($fromStatus));

    $initialHistoryCount = OrderStatusHistory::query()->count();
    $initialAuditCount = StaffAuditLog::query()->count();

    $response = $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => $fromStatus,
            'target_status' => $toStatus,
        ]);

    $response->assertStatus(422);

    expect($order->fresh()->status->value)->toBe($fromStatus)
        ->and(OrderStatusHistory::query()->count())->toBe($initialHistoryCount)
        ->and(StaffAuditLog::query()->count())->toBe($initialAuditCount);
})->with([
    'pending_payment to completed' => ['pending_payment', 'completed'],
    'completed to in_progress' => ['completed', 'in_progress'],
    'cancelled to received' => ['cancelled', 'received'],
    'in_progress to pending_payment' => ['in_progress', 'pending_payment'],
]);

test('transitioning directly to refunded is rejected with 422 even for Admin', function (): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::Received);

    $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'received',
            'target_status' => 'refunded',
        ])
        ->assertStatus(422);

    expect($order->fresh()->status)->toBe(OrderStatus::Received);
});

test('transition request rejecting unknown fields with 422', function (): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::Received);

    $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'received',
            'target_status' => 'in_progress',
            'unauthorized_field' => 'injection_attempt',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['unexpected_fields']);
});

test('transition permissions gate actions per transition type', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = createTransitionTestOrder(OrderStatus::Received);

    // Customer denied
    $this->actingAs($customer)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'received',
            'target_status' => 'in_progress',
        ])
        ->assertForbidden();
});

test('localized transition alias routes execute successfully', function (): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::Received);

    $this->actingAs($admin)
        ->postJson("/en/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'received',
            'target_status' => 'in_progress',
        ])
        ->assertOk()
        ->assertJson([
            'status' => 'in_progress',
        ]);
});

test('an order cannot be paused without something the customer can read', function (): void {
    // The whole defect was a stopped order with a blank explanation. Refusing
    // the transition is what stops that from being possible again.
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::InProgress);

    $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'in_progress',
            'target_status' => 'waiting_for_customer',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect($order->refresh()->status)->toBe(OrderStatus::InProgress)
        ->and(OrderStatusHistory::query()->where('order_id', $order->id)->count())->toBe(0);
});

test('pausing an order freezes the chosen reason in both locales', function (): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::InProgress);

    $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'in_progress',
            'target_status' => 'waiting_for_customer',
            'reason' => 'insufficient_coins',
        ])
        ->assertOk();

    $history = OrderStatusHistory::query()
        ->where('order_id', $order->id)
        ->whereNull('order_item_id')
        ->sole();

    expect($history->status)->toBe(OrderStatusHistoryStatus::WaitingForCustomer)
        ->and($history->note_ar)->toBe(trans('orders.hold_reasons.insufficient_coins', locale: 'ar'))
        ->and($history->note_en)->toBe(trans('orders.hold_reasons.insufficient_coins', locale: 'en'))
        ->and($history->note_ar)->not->toBe($history->note_en);
});

test('a free note is kept alongside the curated reason', function (): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::InProgress);

    $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'in_progress',
            'target_status' => 'waiting_for_customer',
            'reason' => 'market_locked',
            'note' => '  Your market opens on 3 September.  ',
        ])
        ->assertOk();

    $history = OrderStatusHistory::query()
        ->where('order_id', $order->id)
        ->whereNull('order_item_id')
        ->sole();

    // The note is order-specific, so it reads the same in both locales, but it
    // never replaces the curated explanation - it follows it.
    expect($history->note_ar)
        ->toStartWith(trans('orders.hold_reasons.market_locked', locale: 'ar'))
        ->toEndWith('Your market opens on 3 September.')
        ->and($history->note_en)->toEndWith('Your market opens on 3 September.');
});

test('a note alone is enough to pause an order', function (): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::InProgress);

    $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'in_progress',
            'target_status' => 'waiting_for_customer',
            'note' => 'We need a screenshot of your club.',
        ])
        ->assertOk();

    $history = OrderStatusHistory::query()
        ->where('order_id', $order->id)
        ->whereNull('order_item_id')
        ->sole();

    expect($history->note_ar)->toBe('We need a screenshot of your club.')
        ->and($history->note_en)->toBe('We need a screenshot of your club.');
});

test('an unknown reason is refused rather than written as a blank message', function (): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::InProgress);

    $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'in_progress',
            'target_status' => 'waiting_for_customer',
            'reason' => 'dog_ate_the_coins',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect($order->refresh()->status)->toBe(OrderStatus::InProgress);
});
function createTransitionTestActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINTRANSITIONSTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}

function createTransitionTestOrder(OrderStatus $status): Order
{
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
    ]);

    return Order::factory()->for($customer)->create([
        'order_number' => 'AUT-TRANS-'.Str::random(6),
        'status' => $status,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'wallet_halalah' => 0,
        'payment_halalah' => 5000,
        'total_halalah' => 5000,
        'currency' => 'SAR',
        'placed_at' => now(),
    ]);
}

test('cancelling a wallet-funded order tells the customer their money came back', function (): void {
    // The cancellation returns real money to the wallet. Saying only "cancelled"
    // leaves the customer to discover their balance is whole again on their own,
    // or to assume it is not.
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::WaitingForCustomer);
    $order->forceFill(['wallet_halalah' => 4_500, 'payment_halalah' => 500])->save();

    $order->items()->create([
        'sku' => 'AUT-ITEM-WALLET',
        'name_ar' => 'عنصر',
        'name_en' => 'Item',
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::WaitingForCustomer,
        'quantity' => 1,
        'unit_price_halalah' => 5000,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'total_halalah' => 5000,
    ]);

    $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'waiting_for_customer',
            'target_status' => 'cancelled',
        ])
        ->assertOk();

    $note = OrderStatusHistory::query()
        ->where('order_id', $order->id)
        ->whereNull('order_item_id')
        ->latest('id')
        ->sole();

    // 45.00 is the wallet half alone, not the 50.00 total. The gateway half is
    // not refunded on this path, so a figure covering both would be a promise
    // nothing keeps.
    expect($note->note_ar)->toContain('45.00')
        ->and($note->note_en)->toContain('45.00')
        ->and($note->note_en)->not->toContain('50.00');
});

test('cancelling an order that held no wallet money names no figure', function (): void {
    $admin = createTransitionTestActor(UserRole::Admin);
    $order = createTransitionTestOrder(OrderStatus::WaitingForCustomer);

    $order->items()->create([
        'sku' => 'AUT-ITEM-CARD',
        'name_ar' => 'عنصر',
        'name_en' => 'Item',
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::WaitingForCustomer,
        'quantity' => 1,
        'unit_price_halalah' => 5000,
        'subtotal_halalah' => 5000,
        'discount_halalah' => 0,
        'total_halalah' => 5000,
    ]);

    $this->actingAs($admin)
        ->postJson("/admin/orders/{$order->public_id}/transitions", [
            'expected_status' => 'waiting_for_customer',
            'target_status' => 'cancelled',
        ])
        ->assertOk();

    expect(OrderStatusHistory::query()
        ->where('order_id', $order->id)
        ->whereNull('order_item_id')
        ->latest('id')
        ->sole()
        ->note_en)->toBeNull();
});
