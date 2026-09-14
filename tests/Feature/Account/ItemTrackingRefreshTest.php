<?php

use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function refreshTestOrder(User $user, OrderStatus $status = OrderStatus::InProgress): Order
{
    return Order::factory()->for($user)->create([
        'order_number' => 'UT-'.fake()->unique()->numerify('########'),
        'status' => $status,
        'placed_at' => now(),
    ]);
}

function refreshTestItem(
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
function refreshTestJob(OrderItem $item, array $attributes = []): FulfillmentJob
{
    return FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'fft-order-'.fake()->unique()->numerify('#####'),
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    config()->set('services.suppliers.fft.base_url', 'https://fft.example.test');
    config()->set('services.suppliers.fft.api_user', 'store-fft');
    config()->set('services.suppliers.fft.api_key', 'fft-key');
    Http::preventStrayRequests();
});

test('a successful refresh calls the supplier once, applies the observation, and returns the updated tracking object with a newer observedAt', function (): void {
    $owner = User::factory()->create();
    $order = refreshTestOrder($owner);
    $item = refreshTestItem($order, ServiceType::Coins);
    $initialObserved = CarbonImmutable::parse('2026-09-12 10:00:00', 'UTC');

    $job = refreshTestJob($item, [
        'supplier' => Supplier::Fft,
        'supplier_order_id' => '0f8fad5b-d9cb-469f-a165-70867728950e',
        'delivery_phase' => DeliveryPhase::Coins,
        'observed_at' => $initialObserved,
        'coins_delivered' => 10_000,
        'coins_ordered' => 50_000,
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response([
            'status' => 'started',
            'amount' => 30,
            'amountOrdered' => 50,
        ]),
    ]);

    $response = $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/tracking")
        ->assertOk();

    Http::assertSentCount(1);

    expect($response->json('tracking.progress.coinsDelivered'))->toBe(30_000)
        ->and($response->json('tracking.progress.coinsOrdered'))->toBe(50_000)
        ->and($response->json('tracking.observedAt'))->not->toBe($initialObserved->toIso8601String());

    $refreshedJob = $job->fresh();
    expect($refreshedJob->coins_delivered)->toBe(30_000)
        ->and($refreshedJob->observed_at->isAfter($initialObserved))->toBeTrue();
});

test('a successful refresh works on the localized route', function (): void {
    $owner = User::factory()->create();
    $order = refreshTestOrder($owner);
    $item = refreshTestItem($order, ServiceType::Coins);

    refreshTestJob($item, [
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'fft-localized-order',
        'delivery_phase' => DeliveryPhase::Coins,
        'coins_delivered' => 10_000,
        'coins_ordered' => 50_000,
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response([
            'status' => 'started',
            'amount' => 45,
            'amountOrdered' => 50,
        ]),
    ]);

    $response = $this->actingAs($owner)
        ->postJson("/en/orders/{$order->order_number}/items/{$item->public_id}/tracking")
        ->assertOk();

    Http::assertSentCount(1);
    expect($response->json('tracking.progress.coinsDelivered'))->toBe(45_000);
});

test('the de-duplication: with the lock already held, a second request makes no http call and still returns 200 with the stored object', function (): void {
    $owner = User::factory()->create();
    $order = refreshTestOrder($owner);
    $item = refreshTestItem($order, ServiceType::Coins);
    $storedObserved = CarbonImmutable::parse('2026-09-12 11:00:00', 'UTC');

    $job = refreshTestJob($item, [
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'fft-lock-test',
        'observed_at' => $storedObserved,
        'coins_delivered' => 15_000,
        'coins_ordered' => 50_000,
    ]);

    // Acquire the lock beforehand to simulate an in-flight refresh by another viewer
    $lock = Cache::lock("tracking-refresh:job:{$job->id}", 10);
    expect($lock->get())->toBeTrue();

    Http::fake();

    $response = $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/tracking")
        ->assertOk();

    Http::assertNothingSent();

    expect($response->json('tracking.progress.coinsDelivered'))->toBe(15_000)
        ->and($response->json('tracking.observedAt'))->toBe('2026-09-12T11:00:00+00:00');

    $lock->release();
});

test('refusal 1: manual service item returns the stored object and sends nothing', function (ServiceType $manualType): void {
    $owner = User::factory()->create();
    $order = refreshTestOrder($owner);
    $item = refreshTestItem($order, $manualType);

    refreshTestJob($item, [
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'manual-job-1',
    ]);

    Http::fake();

    $response = $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/tracking")
        ->assertOk();

    Http::assertNothingSent();
    expect($response->json('tracking'))->toBeNull();
})->with([
    'Objectives' => [ServiceType::Objectives],
    'Rivals' => [ServiceType::Rivals],
    'FUT Champions' => [ServiceType::FutChampions],
]);

test('refusal 2: item with no fulfillment job or missing supplier order ID returns stored object and sends nothing', function (): void {
    $owner = User::factory()->create();
    $order = refreshTestOrder($owner);
    $itemNoJob = refreshTestItem($order, ServiceType::Coins);

    Http::fake();

    $responseNoJob = $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$itemNoJob->public_id}/tracking")
        ->assertOk();

    Http::assertNothingSent();
    expect($responseNoJob->json('tracking'))->toBeNull();

    $itemNoSupplier = refreshTestItem($order, ServiceType::Coins);
    refreshTestJob($itemNoSupplier, [
        'supplier' => null,
        'supplier_order_id' => null,
    ]);

    $responseNoSupplier = $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$itemNoSupplier->public_id}/tracking")
        ->assertOk();

    Http::assertNothingSent();
    expect($responseNoSupplier->json('tracking'))->toBeNull();
});

test('refusal 3: terminal order returns stored object and sends nothing', function (OrderStatus $terminalStatus): void {
    $owner = User::factory()->create();
    $order = refreshTestOrder($owner, $terminalStatus);
    $item = refreshTestItem($order, ServiceType::Coins);
    $storedObserved = CarbonImmutable::parse('2026-09-12 11:30:00', 'UTC');

    refreshTestJob($item, [
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'terminal-job',
        'observed_at' => $storedObserved,
        'coins_delivered' => 50_000,
        'coins_ordered' => 50_000,
    ]);

    Http::fake();

    $response = $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/tracking")
        ->assertOk();

    Http::assertNothingSent();

    expect($response->json('tracking.progress.coinsDelivered'))->toBe(50_000)
        ->and($response->json('tracking.observedAt'))->toBe('2026-09-12T11:30:00+00:00');
})->with([
    'Completed' => [OrderStatus::Completed],
    'Cancelled' => [OrderStatus::Cancelled],
    'Refunded' => [OrderStatus::Refunded],
]);

test('a SupplierUnavailable returns 200 with the stored object, not a 5xx', function (int $errorStatus): void {
    $owner = User::factory()->create();
    $order = refreshTestOrder($owner);
    $item = refreshTestItem($order, ServiceType::Coins);
    $storedObserved = CarbonImmutable::parse('2026-09-12 09:00:00', 'UTC');

    $job = refreshTestJob($item, [
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'fft-unavailable-test',
        'observed_at' => $storedObserved,
        'coins_delivered' => 5_000,
        'coins_ordered' => 50_000,
        'last_viewed_at' => null,
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response('Upstream down', $errorStatus),
    ]);

    $response = $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/tracking")
        ->assertOk();

    expect($response->json('tracking.progress.coinsDelivered'))->toBe(5_000)
        ->and($response->json('tracking.observedAt'))->toBe('2026-09-12T09:00:00+00:00');

    expect($job->fresh()->last_viewed_at)->toBeNull();
})->with([
    'Bad Gateway' => [502],
    'Service Unavailable' => [503],
    'Gateway Timeout' => [504],
]);

test('another customer order is refused with 404', function (): void {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();

    $orderA = refreshTestOrder($ownerA);
    $itemA = refreshTestItem($orderA, ServiceType::Coins);
    refreshTestJob($itemA);

    Http::fake();

    // Customer B attempts to refresh Customer A's order item
    $this->actingAs($ownerB)
        ->postJson("/orders/{$orderA->order_number}/items/{$itemA->public_id}/tracking")
        ->assertNotFound();

    Http::assertNothingSent();
});

test('unauthenticated visitor is refused', function (): void {
    $owner = User::factory()->create();
    $order = refreshTestOrder($owner);
    $item = refreshTestItem($order, ServiceType::Coins);
    refreshTestJob($item);

    $this->postJson("/orders/{$order->order_number}/items/{$item->public_id}/tracking")
        ->assertUnauthorized();
});

test('item not belonging to the order is refused with 404', function (): void {
    $owner = User::factory()->create();
    $orderA = refreshTestOrder($owner);
    $orderB = refreshTestOrder($owner);

    $itemB = refreshTestItem($orderB, ServiceType::Coins);
    refreshTestJob($itemB);

    Http::fake();

    // Requesting itemB under orderA
    $this->actingAs($owner)
        ->postJson("/orders/{$orderA->order_number}/items/{$itemB->public_id}/tracking")
        ->assertNotFound();

    Http::assertNothingSent();
});

test('last_viewed_at is stamped on success and not on a refusal', function (): void {
    $owner = User::factory()->create();
    $order = refreshTestOrder($owner);
    $item = refreshTestItem($order, ServiceType::Coins);

    $job = refreshTestJob($item, [
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'fft-viewed-stamp-test',
        'last_viewed_at' => null,
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response([
            'status' => 'started',
            'coinsDelivered' => 10_000,
            'coinsTotal' => 50_000,
        ]),
    ]);

    $this->actingAs($owner)
        ->postJson("/orders/{$order->order_number}/items/{$item->public_id}/tracking")
        ->assertOk();

    $refreshed = $job->fresh();
    expect($refreshed->last_viewed_at)->not->toBeNull();

    // Verify it is not stamped on a terminal refusal
    $terminalOrder = refreshTestOrder($owner, OrderStatus::Completed);
    $terminalItem = refreshTestItem($terminalOrder, ServiceType::Coins);
    $terminalJob = refreshTestJob($terminalItem, [
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'terminal-stamp-test',
        'last_viewed_at' => null,
    ]);

    $this->actingAs($owner)
        ->postJson("/orders/{$terminalOrder->order_number}/items/{$terminalItem->public_id}/tracking")
        ->assertOk();

    expect($terminalJob->fresh()->last_viewed_at)->toBeNull();
});

test('the throttle applies per order and per user', function (): void {
    $owner = User::factory()->create();
    $order1 = refreshTestOrder($owner);
    $item1 = refreshTestItem($order1, ServiceType::Coins);
    refreshTestJob($item1, [
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'order1-item',
    ]);

    $order2 = refreshTestOrder($owner);
    $item2 = refreshTestItem($order2, ServiceType::Coins);
    refreshTestJob($item2, [
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'order2-item',
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response(['status' => 'started']),
    ]);

    // Order 1 allows 10 requests per minute
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($owner)
            ->postJson("/orders/{$order1->order_number}/items/{$item1->public_id}/tracking")
            ->assertOk();
    }

    // 11th request for Order 1 is throttled
    $this->actingAs($owner)
        ->postJson("/orders/{$order1->order_number}/items/{$item1->public_id}/tracking")
        ->assertStatus(429);

    // But Order 2 is NOT exhausted: the customer can still refresh Order 2
    $this->actingAs($owner)
        ->postJson("/orders/{$order2->order_number}/items/{$item2->public_id}/tracking")
        ->assertOk();
});
