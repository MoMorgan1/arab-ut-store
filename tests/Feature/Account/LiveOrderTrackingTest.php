<?php

use App\Account\Presenters\ItemTracking;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderHoldReason;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function trackingOrder(User $user, OrderStatus $status = OrderStatus::InProgress): Order
{
    return Order::factory()->for($user)->create([
        'order_number' => 'UT-'.fake()->unique()->numerify('########'),
        'status' => $status,
        'placed_at' => now(),
    ]);
}

function trackingItem(
    Order $order,
    ServiceType $serviceType = ServiceType::Coins,
    OrderItemStatus $status = OrderItemStatus::InProgress,
): OrderItem {
    return OrderItem::factory()->for($order)->create([
        'name_ar' => 'خدمة كوينز',
        'name_en' => 'Coins Service',
        'service_type' => $serviceType,
        'platform' => Platform::PlayStation,
        'status' => $status,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function trackingJob(OrderItem $item, array $attributes = []): FulfillmentJob
{
    // A placed job by default: a supplier and its reference. An observation cannot
    // exist before placement, so a job carrying observation data without a
    // reference is a state the system never reaches - and a fixture that builds
    // one tests nothing real. Individual tests override these to model an
    // unplaced job deliberately.
    return FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'fft-'.$item->id,
        ...$attributes,
    ]);
}

test('rule 1: observedAt is an ISO 8601 timestamp in UTC and not a precomputed relative age', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);
    $observedInstant = CarbonImmutable::parse('2026-09-12 10:30:00', 'UTC');

    trackingJob($item, [
        'supplier' => Supplier::Fft,
        'delivery_phase' => DeliveryPhase::Coins,
        'observed_at' => $observedInstant,
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('account/live-order')
        ->where('order.items.0.tracking.observedAt', '2026-09-12T10:30:00+00:00')
    );

    $tracking = $response->inertiaPage()['props']['order']['items'][0]['tracking'];
    expect($tracking['observedAt'])->toBe('2026-09-12T10:30:00+00:00')
        ->and($tracking['observedAt'])->not->toMatch('/(ago|minute|second|hour|day)/i');
});

test('rule 1: observedAt is null when the fulfillment job has never been observed', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, [
        'supplier' => Supplier::Fft,
        'observed_at' => null,
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking.observedAt', null)
    );
});

test('rule 2: a manual-service item carries status only and tracking is null even if a job exists', function (
    ServiceType $serviceType,
): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, $serviceType);

    // Even if a fulfillment job row exists in the database, manual services must never track
    trackingJob($item, [
        'supplier' => Supplier::Fft,
        'observed_at' => now(),
    ]);

    expect($item->service_type->isManual())->toBeTrue();

    $response = $this->actingAs($owner)
        ->get('/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('account/live-order')
        ->where('order.items.0.status', $item->status->forCustomer()->value)
        ->where('order.items.0.tracking', null)
    );
})->with([
    'Objectives' => [ServiceType::Objectives],
    'Rivals' => [ServiceType::Rivals],
    'FUT Champions' => [ServiceType::FutChampions],
]);

test('rule 3: an automated item with no fulfillment job gets null rather than an empty object', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    expect($item->fulfillmentJob)->toBeNull();

    $response = $this->actingAs($owner)
        ->get('/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking', null)
    );

    $items = $response->inertiaPage()['props']['order']['items'];
    expect($items[0]['tracking'])->toBeNull();
});

test('rule 4: progress is null when every counter is null', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, [
        'coins_delivered' => null,
        'coins_ordered' => null,
        'challenges_solved' => null,
        'challenges_requested' => null,
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking.progress', null)
    );
});

test('rule 4: progress is populated when counters are present', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Sbc);

    trackingJob($item, [
        'coins_delivered' => 50_000,
        'coins_ordered' => 100_000,
        'challenges_solved' => 2,
        'challenges_requested' => 5,
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking.progress', [
            'coinsDelivered' => 50_000,
            'coinsOrdered' => 100_000,
            'challengesSolved' => 2,
            'challengesRequested' => 5,
        ])
    );
});

test('rule 4: progress is not null when a counter is zero', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, [
        'coins_delivered' => 0,
        'coins_ordered' => 250_000,
        'challenges_solved' => null,
        'challenges_requested' => null,
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking.progress', [
            'coinsDelivered' => 0,
            'coinsOrdered' => 250_000,
            'challengesSolved' => null,
            'challengesRequested' => null,
        ])
    );
});

test('rule 5: holdMessage is the localised text and holdReason is the raw enum value in Arabic and English', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, [
        'hold_reason' => OrderHoldReason::BackupCodes,
    ]);

    // Arabic locale
    $arResponse = $this->actingAs($owner)
        ->get('/my-account/orders/'.$order->order_number)
        ->assertOk();

    $arResponse->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking.holdReason', 'backup_codes')
        ->where(
            'order.items.0.tracking.holdMessage',
            'الأكواد الاحتياطية للحساب غير صحيحة أو مستخدمة من قبل. أنشئ أكواد جديدة من إعدادات الأمان في حساب EA ثم حدّث بيانات الطلب.'
        )
    );

    // English locale
    $enResponse = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $enResponse->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking.holdReason', 'backup_codes')
        ->where(
            'order.items.0.tracking.holdMessage',
            'The backup codes on the account are wrong or already used. Create new codes from the security settings of your EA account, then update the order details.'
        )
    );
});

test('rule 5: holdReason and holdMessage are null when the item is not on hold', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, [
        'hold_reason' => null,
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking.holdReason', null)
        ->where('order.items.0.tracking.holdMessage', null)
    );
});

test('rule 6: actions maps allowedActions to string values and is [] when there are none', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $itemWithActions = trackingItem($order, ServiceType::Coins);

    trackingJob($itemWithActions, [
        'allowed_actions' => ['edit_credentials', 'resume', 'unrecognized_stale_action'],
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking.actions', ['edit_credentials', 'resume'])
    );

    $orderNoActions = trackingOrder($owner);
    $itemNoActions = trackingItem($orderNoActions, ServiceType::Coins);
    trackingJob($itemNoActions, [
        'allowed_actions' => null,
    ]);

    $responseEmpty = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$orderNoActions->order_number)
        ->assertOk();

    $trackingEmpty = $responseEmpty->inertiaPage()['props']['order']['items'][0]['tracking'];
    expect($trackingEmpty['actions'])->toBe([]);
});

test('rule 7: neither observed_state nor any key from raw observation column leaks into the payload by name', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, [
        'supplier' => Supplier::Fft,
        'observed_state' => 'STATE_BOT_SOLVING_CAPTCHA_INTERNAL',
        'observation' => [
            'internal_session_token' => 'SECRET_SESSION_TOKEN_123',
            'supplier_worker_ip' => '10.0.0.42',
            'raw_error_message' => 'INTERNAL_SOCKET_TIMEOUT',
        ],
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->missing('order.items.0.tracking.observed_state')
        ->missing('order.items.0.tracking.observedState')
        ->missing('order.items.0.tracking.observation')
        ->missing('order.items.0.observed_state')
        ->missing('order.items.0.observation')
    );

    $rawJson = json_encode($response->inertiaPage(), JSON_THROW_ON_ERROR);

    expect($rawJson)
        ->not->toContain('observed_state')
        ->not->toContain('STATE_BOT_SOLVING_CAPTCHA_INTERNAL')
        ->not->toContain('internal_session_token')
        ->not->toContain('SECRET_SESSION_TOKEN_123')
        ->not->toContain('supplier_worker_ip')
        ->not->toContain('10.0.0.42')
        ->not->toContain('raw_error_message')
        ->not->toContain('INTERNAL_SOCKET_TIMEOUT');
});

test('two items on one order: automated placed item carries tracking while manual item carries null', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);

    $coinsItem = trackingItem($order, ServiceType::Coins);
    $coinsItem->update([
        'name_ar' => 'كوينز 500 ألف',
        'name_en' => '500k Coins',
    ]);

    trackingJob($coinsItem, [
        'supplier' => Supplier::Utt,
        'delivery_phase' => null,
        'hold_reason' => null,
        'allowed_actions' => ['resume'],
        'observation_supported' => true,
        'observed_at' => CarbonImmutable::parse('2026-09-12 11:00:00', 'UTC'),
        'coins_delivered' => 200_000,
        'coins_ordered' => 500_000,
    ]);

    $manualItem = trackingItem($order, ServiceType::Objectives);
    $manualItem->update([
        'name_ar' => 'مهام الأسبوع',
        'name_en' => 'Weekly Objectives',
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->has('order.items', 2)
        ->where('order.items.0.id', $coinsItem->public_id)
        ->where('order.items.0.tracking.supplier', 'utt')
        ->where('order.items.0.tracking.phase', null)
        ->where('order.items.0.tracking.supported', true)
        ->where('order.items.0.tracking.observedAt', '2026-09-12T11:00:00+00:00')
        ->where('order.items.0.tracking.actions', ['resume'])
        ->where('order.items.0.tracking.progress', [
            'coinsDelivered' => 200_000,
            'coinsOrdered' => 500_000,
            'challengesSolved' => null,
            'challengesRequested' => null,
        ])
        ->where('order.items.1.id', $manualItem->public_id)
        ->where('order.items.1.tracking', null)
    );
});

test('tracking carries delivery phase and supported false accurately', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Sbc);

    trackingJob($item, [
        'supplier' => Supplier::Fft,
        'delivery_phase' => DeliveryPhase::Challenge,
        'observation_supported' => false,
        'observed_at' => CarbonImmutable::parse('2026-09-12 14:00:00', 'UTC'),
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking.supplier', 'fft')
        ->where('order.items.0.tracking.phase', 'challenge')
        ->where('order.items.0.tracking.supported', false)
        ->where('order.items.0.tracking.observedAt', '2026-09-12T14:00:00+00:00')
    );
});

test('ItemTracking presenter direct invocation returns expected shape', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, [
        'supplier' => Supplier::Fft,
        'delivery_phase' => DeliveryPhase::Coins,
        'hold_reason' => OrderHoldReason::EaServers,
        'allowed_actions' => ['retry_challenge'],
        'observation_supported' => true,
        'observed_at' => CarbonImmutable::parse('2026-09-12 15:00:00', 'UTC'),
        'coins_delivered' => 100_000,
        'coins_ordered' => 200_000,
        'challenges_solved' => 1,
        'challenges_requested' => 1,
    ]);

    $tracking = ItemTracking::for($item, 'en');

    expect($tracking)->toBe([
        'supplier' => 'fft',
        'phase' => 'coins',
        'holdReason' => 'ea_servers',
        'holdMessage' => 'EA servers refused the sign-in for now. We are retrying and will update you as soon as it works.',
        'actions' => ['retry_challenge'],
        'supported' => true,
        'observedAt' => '2026-09-12T15:00:00+00:00',
        'progress' => [
            'coinsDelivered' => 100_000,
            'coinsOrdered' => 200_000,
            'challengesSolved' => 1,
            'challengesRequested' => 1,
        ],
    ]);
});
