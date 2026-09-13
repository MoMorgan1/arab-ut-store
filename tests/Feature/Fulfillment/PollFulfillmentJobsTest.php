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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

function pollTestOrder(User $user, OrderStatus $status = OrderStatus::InProgress): Order
{
    return Order::factory()->for($user)->create([
        'order_number' => 'UT-'.fake()->unique()->numerify('########'),
        'status' => $status,
        'placed_at' => now(),
    ]);
}

function pollTestItem(
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
function pollTestJob(OrderItem $item, array $attributes = []): FulfillmentJob
{
    return FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'fft-poll-'.fake()->unique()->numerify('#####'),
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    // The array cache and rate limiter persist across tests in one process; a
    // circuit opened or a limiter drained by a previous test must not leak into
    // the next. Locks are not cleared by flush(), so each test that takes one
    // releases it explicitly.
    Cache::flush();

    config()->set('services.suppliers.fft.base_url', 'https://fft.example.test');
    config()->set('services.suppliers.fft.api_user', 'store-fft');
    config()->set('services.suppliers.fft.api_key', 'fft-key');
    Http::preventStrayRequests();
});

test('a due background job is read once and its next_poll_at advances by roughly the background cadence', function (): void {
    $owner = User::factory()->create();
    $order = pollTestOrder($owner);
    $item = pollTestItem($order, ServiceType::Coins);
    $job = pollTestJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'next_poll_at' => now()->subMinute(),
        'last_viewed_at' => null,
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response([
            'status' => 'started',
            'amount' => 10,
            'amountOrdered' => 50,
        ]),
    ]);

    $this->artisan('fulfillment:poll')->assertExitCode(0);

    Http::assertSentCount(1);

    $fresh = $job->fresh();
    expect($fresh->next_poll_at)->not->toBeNull()
        ->and($fresh->next_poll_at->timestamp)->toBeGreaterThan(now()->addSeconds(170)->timestamp)
        ->and($fresh->next_poll_at->timestamp)->toBeLessThan(now()->addSeconds(240)->timestamp);
});

test('a job watched thirty seconds ago advances by the attention cadence, not the background one', function (): void {
    $owner = User::factory()->create();
    $order = pollTestOrder($owner);
    $item = pollTestItem($order, ServiceType::Coins);
    $job = pollTestJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'next_poll_at' => now()->subMinute(),
        'last_viewed_at' => now()->subSeconds(30),
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response(['status' => 'started']),
    ]);

    $this->artisan('fulfillment:poll')->assertExitCode(0);

    $fresh = $job->fresh();
    expect($fresh->next_poll_at)->not->toBeNull()
        ->and($fresh->next_poll_at->timestamp)->toBeGreaterThan(now()->addSeconds(20)->timestamp)
        ->and($fresh->next_poll_at->timestamp)->toBeLessThan(now()->addSeconds(40)->timestamp);
});

test('the attention band is served before the background band when both are due and the limit is one', function (): void {
    $owner = User::factory()->create();
    $order = pollTestOrder($owner);

    $backgroundItem = pollTestItem($order, ServiceType::Coins);
    pollTestJob($backgroundItem, [
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier_order_id' => 'fft-background',
        'next_poll_at' => now()->subSeconds(100),
        'last_viewed_at' => null,
    ]);

    $attentionItem = pollTestItem($order, ServiceType::Coins);
    pollTestJob($attentionItem, [
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier_order_id' => 'fft-attention',
        'next_poll_at' => now()->subSeconds(10),
        'last_viewed_at' => now()->subSeconds(30),
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response(['status' => 'started']),
    ]);

    $this->artisan('fulfillment:poll', ['--limit' => 1])->assertExitCode(0);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->data()['orderID'] === 'fft-attention');
});

test('a job with a null next_poll_at is never read', function (): void {
    $owner = User::factory()->create();
    $order = pollTestOrder($owner);
    $item = pollTestItem($order, ServiceType::Coins);
    $job = pollTestJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'next_poll_at' => null,
    ]);

    Http::fake();

    $this->artisan('fulfillment:poll')->assertExitCode(0);

    Http::assertNothingSent();
    expect($job->fresh()->next_poll_at)->toBeNull();
});

test('a job whose order is completed is never read even with a due next_poll_at', function (): void {
    $owner = User::factory()->create();
    $order = pollTestOrder($owner, OrderStatus::Completed);
    $item = pollTestItem($order, ServiceType::Coins);
    $job = pollTestJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'next_poll_at' => now()->subMinute(),
    ]);

    Http::fake();

    $this->artisan('fulfillment:poll')->assertExitCode(0);

    Http::assertNothingSent();
    expect($job->fresh()->next_poll_at)->not->toBeNull();
});

test('an observation that completes the job leaves next_poll_at null', function (): void {
    Notification::fake();

    $owner = User::factory()->create();
    $order = pollTestOrder($owner);
    $item = pollTestItem($order, ServiceType::Coins);
    $job = pollTestJob($item, [
        'delivery_phase' => null,
        'next_poll_at' => now()->subMinute(),
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response(['status' => 'finished']),
    ]);

    $this->artisan('fulfillment:poll')->assertExitCode(0);

    $fresh = $job->fresh();
    expect($fresh->status)->toBe(FulfillmentStatus::Completed)
        ->and($fresh->next_poll_at)->toBeNull();
});

test('a SupplierUnavailable backs off further than the cadence, counts the failure, and exits zero', function (): void {
    $owner = User::factory()->create();
    $order = pollTestOrder($owner);
    $item = pollTestItem($order, ServiceType::Coins);
    $job = pollTestJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'next_poll_at' => now()->subMinute(),
        'poll_failure_count' => 0,
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response('Upstream down', 502),
    ]);

    $this->artisan('fulfillment:poll')->assertExitCode(0);

    $fresh = $job->fresh();
    expect($fresh->poll_failure_count)->toBe(1)
        ->and($fresh->next_poll_at)->not->toBeNull()
        ->and($fresh->next_poll_at->timestamp)->toBeGreaterThan(now()->addSeconds(300)->timestamp);
});

test('a second invocation with the tick lock held reads no supplier and exits zero', function (): void {
    $owner = User::factory()->create();
    $order = pollTestOrder($owner);
    $item = pollTestItem($order, ServiceType::Coins);
    pollTestJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'next_poll_at' => now()->subMinute(),
    ]);

    Http::fake();

    $lock = Cache::lock('fulfillment:poll', 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('fulfillment:poll')->assertExitCode(0);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

test('a leased job is skipped while an expired lease is read and released', function (): void {
    $owner = User::factory()->create();
    $order = pollTestOrder($owner);

    $otherItem = pollTestItem($order, ServiceType::Coins);
    $otherJob = pollTestJob($otherItem, [
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier_order_id' => 'fft-leased-other',
        'next_poll_at' => now()->subMinute(),
        'leased_until' => now()->addSeconds(60),
        'lease_token' => 'other-token',
    ]);

    $expiredItem = pollTestItem($order, ServiceType::Coins);
    $expiredJob = pollTestJob($expiredItem, [
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier_order_id' => 'fft-leased-expired',
        'next_poll_at' => now()->subMinute(),
        'leased_until' => now()->subSeconds(60),
        'lease_token' => 'expired-token',
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response(['status' => 'started']),
    ]);

    $this->artisan('fulfillment:poll')->assertExitCode(0);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->data()['orderID'] === 'fft-leased-expired');

    expect($otherJob->fresh()->lease_token)->toBe('other-token')
        ->and($otherJob->fresh()->leased_until)->not->toBeNull()
        ->and($expiredJob->fresh()->lease_token)->toBeNull()
        ->and($expiredJob->fresh()->leased_until)->toBeNull();
});

test('the sweep does not stamp last_viewed_at', function (): void {
    $owner = User::factory()->create();
    $order = pollTestOrder($owner);
    $item = pollTestItem($order, ServiceType::Coins);
    $job = pollTestJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'next_poll_at' => now()->subMinute(),
        'last_viewed_at' => null,
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response(['status' => 'started']),
    ]);

    $this->artisan('fulfillment:poll')->assertExitCode(0);

    expect($job->fresh()->last_viewed_at)->toBeNull();
});

test('a supplier with an open circuit is not called and its jobs poll at or after the close time', function (): void {
    $owner = User::factory()->create();
    $order = pollTestOrder($owner);
    $item = pollTestItem($order, ServiceType::Coins);
    $job = pollTestJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'next_poll_at' => now()->subMinute(),
    ]);

    $closeAt = CarbonImmutable::now()->addSeconds(120);
    Cache::put('supplier:fft:circuit', $closeAt->getTimestamp(), 120);

    Http::fake();

    $this->artisan('fulfillment:poll')->assertExitCode(0);

    Http::assertNothingSent();

    $fresh = $job->fresh();
    expect($fresh->next_poll_at)->not->toBeNull()
        ->and($fresh->next_poll_at->timestamp)->toBeGreaterThanOrEqual($closeAt->timestamp);
});

test('the tick emits one summary log line carrying the counts', function (): void {
    Log::spy();

    $owner = User::factory()->create();
    $order = pollTestOrder($owner);
    $item = pollTestItem($order, ServiceType::Coins);
    pollTestJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'next_poll_at' => now()->subMinute(),
    ]);

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response(['status' => 'started']),
    ]);

    $this->artisan('fulfillment:poll')->assertExitCode(0);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Fulfillment poll completed.'
                && $context['passes'] === 1
                && $context['attempted'] === 1
                && $context['observed'] === 1
                && $context['unreadable'] === 0
                && $context['unavailable'] === 0
                && $context['not_configured'] === 0
                && $context['busy'] === 0
                && $context['circuit_skipped'] === 0
                && $context['deadline_hit'] === false
                // A duration, not a signed difference: the negative one this
                // caught was invisible to an isset check.
                && is_int($context['duration_ms'])
                && $context['duration_ms'] >= 0
                && isset($context['latency_p50_ms'], $context['latency_p95_ms'])
                && isset($context['per_supplier']['fft'])
                && $context['per_supplier']['fft']['attempted'] === 1
                && $context['per_supplier']['fft']['observed'] === 1;
        });
});
