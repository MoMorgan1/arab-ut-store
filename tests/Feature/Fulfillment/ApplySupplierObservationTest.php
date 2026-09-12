<?php

require_once dirname(__DIR__).'/Loyalty/LoyaltyFixtures.php';

use App\Actions\Fulfillment\ApplySupplierObservation;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderHoldReason;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\PaymentStatus;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Models\WalletEntry;
use App\Notifications\ReviewInviteNotification;
use App\Suppliers\Translation\TranslatedState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Creates an order, item, and linked fulfillment job for observation tests.
 *
 * @param  array<string, mixed>  $orderAttributes
 * @param  array<string, mixed>  $itemAttributes
 * @param  array<string, mixed>  $jobAttributes
 * @return array{0: Order, 1: OrderItem, 2: FulfillmentJob}
 */
function createObservationContext(
    OrderStatus $orderStatus = OrderStatus::InProgress,
    OrderItemStatus $itemStatus = OrderItemStatus::InProgress,
    array $orderAttributes = [],
    array $itemAttributes = [],
    array $jobAttributes = [],
    bool $live = false,
): array {
    $customer = User::factory()->create();

    $order = Order::factory()->for($customer)->create([
        'status' => $orderStatus,
        'channel' => 'store',
        'currency' => 'SAR',
        'paid_at' => now(),
        'subtotal_halalah' => 20_000,
        'payment_halalah' => 20_000,
        'total_halalah' => 20_000,
        ...$orderAttributes,
    ]);

    $item = OrderItem::factory()->for($order)->create([
        'status' => $itemStatus,
        'unit_price_halalah' => 20_000,
        'subtotal_halalah' => 20_000,
        'total_halalah' => 20_000,
        ...$itemAttributes,
    ]);

    $jobFactory = $live ? FulfillmentJob::factory()->live() : FulfillmentJob::factory();

    $job = $jobFactory->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'delivery_phase' => DeliveryPhase::Coins,
        ...$jobAttributes,
    ]);

    return [$order, $item, $job];
}

it('discards older observations under the lock (Rule 1)', function (): void {
    [$order, $item, $job] = createObservationContext(
        jobAttributes: [
            'observed_at' => CarbonImmutable::parse('2026-09-12 12:03:00'),
            'observed_state' => 'initial',
        ],
    );

    // Simulate a concurrent writer that committed a newer observation to the database
    // after this worker fetched the model but before acquiring the transaction lock.
    // The in-memory $job still has 12:03:00 (bypassing pre-lock fast path at :48-50),
    // but the locked read at :77-80 will see 12:05:00.
    FulfillmentJob::query()->where('id', $job->id)->update([
        'observed_at' => CarbonImmutable::parse('2026-09-12 12:05:00'),
        'observed_state' => 'concurrent_winner',
    ]);

    $state = new TranslatedState(
        status: OrderStatus::InProgress,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'stale_working',
    );

    $staleTimestamp = CarbonImmutable::parse('2026-09-12 12:04:00');

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state,
        observedAt: $staleTimestamp,
        rawPayload: ['status' => 'stale_working'],
    );

    $freshJob = $job->fresh();
    expect($freshJob->observed_at?->toDateTimeString())->toBe('2026-09-12 12:05:00')
        ->and($freshJob->observed_state)->toBe('concurrent_winner')
        ->and(OrderStatusHistory::query()->count())->toBe(0);
});

it('discards older observations via the pre-lock fast path (Rule 1)', function (): void {
    [$order, $item, $job] = createObservationContext(
        jobAttributes: [
            'observed_at' => CarbonImmutable::parse('2026-09-12 12:05:00'),
            'observed_state' => 'entered',
        ],
    );

    $state = new TranslatedState(
        status: OrderStatus::InProgress,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'working',
    );

    $staleTimestamp = CarbonImmutable::parse('2026-09-12 12:04:00');

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state,
        observedAt: $staleTimestamp,
        rawPayload: ['status' => 'working'],
    );

    $freshJob = $job->fresh();
    expect($freshJob->observed_at?->toDateTimeString())->toBe('2026-09-12 12:05:00')
        ->and($freshJob->observed_state)->toBe('entered')
        ->and(OrderStatusHistory::query()->count())->toBe(0);
});

it('never moves a terminal order or its items and stores observation for diagnosis (Rule 2)', function (OrderStatus $terminalStatus): void {
    [$order, $item, $job] = createObservationContext(
        orderStatus: $terminalStatus,
        itemStatus: OrderItemStatus::from($terminalStatus->value),
        orderAttributes: [
            'completed_at' => $terminalStatus === OrderStatus::Completed ? now() : null,
            'cancelled_at' => $terminalStatus === OrderStatus::Cancelled ? now() : null,
        ],
        live: true,
    );

    expect($job->next_poll_at)->not->toBeNull();

    $state = new TranslatedState(
        status: OrderStatus::InProgress,
        holdReason: null,
        allowedActions: [SupplierAction::Resume],
        supported: true,
        observedState: 'entered',
        coinsDelivered: 50_000,
        coinsOrdered: 100_000,
    );

    $observedAt = CarbonImmutable::parse('2026-09-12 13:00:00');

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state,
        observedAt: $observedAt,
        rawPayload: ['status' => 'entered', 'coins' => ['delivered' => 50_000]],
    );

    // Order and item must remain untouched in their terminal state
    expect($order->fresh()->status)->toBe($terminalStatus)
        ->and($item->fresh()->status->value)->toBe($terminalStatus->value)
        ->and(OrderStatusHistory::query()->count())->toBe(0);

    // Diagnostic fields are recorded on the job without re-opening polling
    $freshJob = $job->fresh();
    expect($freshJob->observed_at?->toDateTimeString())->toBe('2026-09-12 13:00:00')
        ->and($freshJob->observed_state)->toBe('entered')
        ->and($freshJob->coins_delivered)->toBe(50_000)
        ->and($freshJob->coins_ordered)->toBe(100_000)
        ->and($freshJob->next_poll_at)->toBeNull();
})->with([
    'completed order' => [OrderStatus::Completed],
    'cancelled order' => [OrderStatus::Cancelled],
    'refunded order' => [OrderStatus::Refunded],
]);

it('marks a live job completed when completed observation arrives for a terminal order (Rule 2)', function (): void {
    [$order, $item, $job] = createObservationContext(
        orderStatus: OrderStatus::Completed,
        itemStatus: OrderItemStatus::Completed,
        orderAttributes: [
            'completed_at' => now(),
        ],
        live: true,
    );

    expect($job->next_poll_at)->not->toBeNull();

    $state = new TranslatedState(
        status: OrderStatus::Completed,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'finished',
    );

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state,
        observedAt: now(),
        rawPayload: ['status' => 'finished'],
    );

    $freshJob = $job->fresh();
    expect($freshJob->status)->toBe(FulfillmentStatus::Completed)
        ->and($freshJob->completed_at)->not->toBeNull()
        ->and($freshJob->next_poll_at)->toBeNull();
});

it('respects a manual admin hold on WaitingForCustomer and withholds the observation (Rule 3)', function (): void {
    [$order, $item, $job] = createObservationContext(
        orderStatus: OrderStatus::WaitingForCustomer,
        itemStatus: OrderItemStatus::WaitingForCustomer,
        jobAttributes: [
            'hold_reason' => OrderHoldReason::Credentials,
            'allowed_actions' => [SupplierAction::EditCredentials->value],
        ],
    );

    $admin = User::factory()->create();

    OrderStatusHistory::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'actor_user_id' => $admin->id,
        'status' => OrderStatusHistoryStatus::WaitingForCustomer,
        'note_ar' => 'توقف يدوي',
        'note_en' => 'Manual hold',
        'metadata' => [
            'source' => 'admin',
            'previous_status' => OrderStatus::InProgress->value,
            'new_status' => OrderStatus::WaitingForCustomer->value,
        ],
    ]);

    $state = new TranslatedState(
        status: OrderStatus::InProgress,
        holdReason: OrderHoldReason::Credentials,
        allowedActions: [SupplierAction::Resume],
        supported: true,
        observedState: 'entered',
    );

    $observedAt = CarbonImmutable::parse('2026-09-12 13:00:00');

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state,
        observedAt: $observedAt,
        rawPayload: ['status' => 'entered'],
    );

    // Item must remain in WaitingForCustomer due to the admin hold
    expect($item->fresh()->status)->toBe(OrderItemStatus::WaitingForCustomer);

    // Hold fields must remain untouched
    $freshJob = $job->fresh();
    expect($freshJob->hold_reason)->toBe(OrderHoldReason::Credentials)
        ->and($freshJob->allowed_actions)->toBe([SupplierAction::EditCredentials->value]);

    // Observation must be visibly marked as withheld on the job under the service namespace
    expect($freshJob->observation)->toMatchArray([
        '_service' => [
            'withheld' => true,
            'withheld_reason' => 'admin_hold',
        ],
        'status' => 'entered',
    ]);
});

it('respects an order-level manual admin hold with no item-level history and withholds the observation (Rule 3)', function (): void {
    [$order, $item, $job] = createObservationContext(
        orderStatus: OrderStatus::WaitingForCustomer,
        itemStatus: OrderItemStatus::WaitingForCustomer,
        jobAttributes: [
            'hold_reason' => OrderHoldReason::Credentials,
            'allowed_actions' => [SupplierAction::EditCredentials->value],
        ],
    );

    $admin = User::factory()->create();

    // Order-level history row only; order_item_id is NULL
    OrderStatusHistory::query()->create([
        'order_id' => $order->id,
        'order_item_id' => null,
        'actor_user_id' => $admin->id,
        'status' => OrderStatusHistoryStatus::WaitingForCustomer,
        'note_ar' => 'توقف يدوي للطلب',
        'note_en' => 'Order manual hold',
        'metadata' => [
            'source' => 'admin',
            'previous_status' => OrderStatus::InProgress->value,
            'new_status' => OrderStatus::WaitingForCustomer->value,
        ],
    ]);

    $state = new TranslatedState(
        status: OrderStatus::InProgress,
        holdReason: OrderHoldReason::Credentials,
        allowedActions: [SupplierAction::Resume],
        supported: true,
        observedState: 'entered',
    );

    $observedAt = CarbonImmutable::parse('2026-09-12 13:00:00');

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state,
        observedAt: $observedAt,
        rawPayload: ['status' => 'entered'],
    );

    // Item must not move under an order-level admin hold
    expect($item->fresh()->status)->toBe(OrderItemStatus::WaitingForCustomer);

    // Hold fields must remain untouched
    $freshJob = $job->fresh();
    expect($freshJob->hold_reason)->toBe(OrderHoldReason::Credentials)
        ->and($freshJob->allowed_actions)->toBe([SupplierAction::EditCredentials->value]);

    // Observation must be marked withheld under the service namespace
    expect($freshJob->observation)->toMatchArray([
        '_service' => [
            'withheld' => true,
            'withheld_reason' => 'admin_hold',
        ],
        'status' => 'entered',
    ]);
});

it('allows a non-admin WaitingForCustomer item to be transitioned by a supplier observation (Rule 3)', function (): void {
    [$order, $item, $job] = createObservationContext(
        orderStatus: OrderStatus::WaitingForCustomer,
        itemStatus: OrderItemStatus::WaitingForCustomer,
        jobAttributes: [
            'hold_reason' => OrderHoldReason::Credentials,
            'allowed_actions' => [SupplierAction::EditCredentials->value],
        ],
    );

    OrderStatusHistory::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'actor_user_id' => null,
        'status' => OrderStatusHistoryStatus::WaitingForCustomer,
        'note_ar' => null,
        'note_en' => null,
        'metadata' => [
            'source' => 'supplier',
            'previous_status' => OrderStatus::InProgress->value,
            'new_status' => OrderStatus::WaitingForCustomer->value,
        ],
    ]);

    $state = new TranslatedState(
        status: OrderStatus::InProgress,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'entered',
    );

    $observedAt = CarbonImmutable::parse('2026-09-12 13:00:00');

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state,
        observedAt: $observedAt,
        rawPayload: ['status' => 'entered'],
    );

    // Item successfully transitions because the hold was from a supplier, not an admin
    expect($item->fresh()->status)->toBe(OrderItemStatus::InProgress);

    $latestHistory = OrderStatusHistory::query()
        ->where('order_item_id', $item->id)
        ->latest('id')
        ->first();

    expect($latestHistory?->metadata['source'])->toBe('supplier')
        ->and($latestHistory?->metadata['new_status'])->toBe(OrderStatus::InProgress->value);

    // Hold fields must be updated to the state values when not withheld
    $freshJob = $job->fresh();
    expect($freshJob->hold_reason)->toBeNull()
        ->and($freshJob->allowed_actions)->toBe([])
        ->and($freshJob->observation)->not->toHaveKey('_service');
});

it('refuses to produce a Refunded status from a supplier observation (Rule 4)', function (): void {
    [$order, $item, $job] = createObservationContext();

    $state = new TranslatedState(
        status: OrderStatus::Refunded,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'refunded',
    );

    $observedAt = CarbonImmutable::parse('2026-09-12 13:00:00');

    expect(fn () => app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state,
        observedAt: $observedAt,
        rawPayload: ['status' => 'refunded'],
    ))->toThrow(DomainException::class, 'A supplier observation cannot produce a refunded status.');

    expect($order->fresh()->status)->toBe(OrderStatus::InProgress)
        ->and($item->fresh()->status)->toBe(OrderItemStatus::InProgress);
});

it('aggregates every item completed to order completed (Rule 5)', function (): void {
    [$order, $item1, $job1] = createObservationContext(
        orderStatus: OrderStatus::InProgress,
        itemStatus: OrderItemStatus::InProgress,
    );

    $item2 = OrderItem::factory()->for($order)->create([
        'status' => OrderItemStatus::Completed,
    ]);

    $state = new TranslatedState(
        status: OrderStatus::Completed,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'finished',
    );

    app(ApplySupplierObservation::class)->execute(
        job: $job1,
        state: $state,
        observedAt: now(),
        rawPayload: ['status' => 'finished'],
    );

    expect($item1->fresh()->status)->toBe(OrderItemStatus::Completed)
        ->and($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->fresh()->completed_at)->not->toBeNull();
});

it('prefers WaitingForCustomer over InProgress when aggregating items (Rule 5)', function (): void {
    [$order, $item1, $job1] = createObservationContext(
        orderStatus: OrderStatus::InProgress,
        itemStatus: OrderItemStatus::InProgress,
    );

    $item2 = OrderItem::factory()->for($order)->create([
        'status' => OrderItemStatus::InProgress,
    ]);

    $state = new TranslatedState(
        status: OrderStatus::WaitingForCustomer,
        holdReason: OrderHoldReason::Credentials,
        allowedActions: [SupplierAction::EditCredentials],
        supported: true,
        observedState: 'wrongUserPass',
    );

    app(ApplySupplierObservation::class)->execute(
        job: $job1,
        state: $state,
        observedAt: now(),
        rawPayload: ['status' => 'wrongUserPass'],
    );

    // Waiting beats progress: item 2 is in progress, but item 1 needs customer action
    expect($item1->fresh()->status)->toBe(OrderItemStatus::WaitingForCustomer)
        ->and($item2->fresh()->status)->toBe(OrderItemStatus::InProgress)
        ->and($order->fresh()->status)->toBe(OrderStatus::WaitingForCustomer);
});

it('aggregates to InProgress when one item is completed and another is in progress (Rule 5)', function (): void {
    [$order, $item1, $job1] = createObservationContext(
        orderStatus: OrderStatus::Received,
        itemStatus: OrderItemStatus::Received,
    );

    $item2 = OrderItem::factory()->for($order)->create([
        'status' => OrderItemStatus::Completed,
    ]);

    $state = new TranslatedState(
        status: OrderStatus::InProgress,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'entered',
    );

    app(ApplySupplierObservation::class)->execute(
        job: $job1,
        state: $state,
        observedAt: now(),
        rawPayload: ['status' => 'entered'],
    );

    expect($item1->fresh()->status)->toBe(OrderItemStatus::InProgress)
        ->and($item2->fresh()->status)->toBe(OrderItemStatus::Completed)
        ->and($order->fresh()->status)->toBe(OrderStatus::InProgress);
});

it('never moves the order to Cancelled because of an observation (Rule 5)', function (): void {
    [$order, $item1, $job1] = createObservationContext(
        orderStatus: OrderStatus::InProgress,
        itemStatus: OrderItemStatus::InProgress,
    );

    $state = new TranslatedState(
        status: OrderStatus::Cancelled,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'cancelled',
    );

    app(ApplySupplierObservation::class)->execute(
        job: $job1,
        state: $state,
        observedAt: now(),
        rawPayload: ['status' => 'cancelled'],
    );

    // The item was cancelled by the supplier, but the order stays InProgress for human decision
    expect($item1->fresh()->status)->toBe(OrderItemStatus::Cancelled)
        ->and($order->fresh()->status)->toBe(OrderStatus::InProgress);
});

it('fires completion effects once and is idempotent against repeated completions (Rule 6)', function (): void {
    Carbon::setTestNow('2026-09-12 12:00:00');
    Notification::fake();
    config()->set('store.features.loyalty_enabled', true);
    loyaltySeedTiers();

    [$order, $item, $job] = createObservationContext(
        orderStatus: OrderStatus::InProgress,
        itemStatus: OrderItemStatus::InProgress,
    );

    loyaltySettledPayment($order, PaymentStatus::Paid, 20_000);

    $state = new TranslatedState(
        status: OrderStatus::Completed,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'finished',
    );

    $action = app(ApplySupplierObservation::class);

    // First completion
    $action->execute(
        job: $job,
        state: $state,
        observedAt: now(),
        rawPayload: ['status' => 'finished'],
    );

    expect($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->fresh()->review_invited_at)->not->toBeNull();

    // Cashback accrued exactly once
    $cashbackEntries = WalletEntry::query()
        ->where('reference', "cashback:{$order->id}")
        ->get();
    expect($cashbackEntries)->toHaveCount(1);

    // Review invite notification queued once
    Notification::assertSentTo(
        $order->user,
        ReviewInviteNotification::class,
        function (ReviewInviteNotification $notification): bool {
            return $notification->delay?->toDateTimeString() === '2026-09-12 13:00:00';
        },
    );

    // Reset order and item status to InProgress so Rule 2 does not short-circuit,
    // allowing the second execute to aggregate to Completed and genuinely reach completion effects.
    $order->update(['status' => OrderStatus::InProgress]);
    $item->update(['status' => OrderItemStatus::InProgress]);

    // Repeat completion call
    $action->execute(
        job: $job,
        state: $state,
        observedAt: now()->addMinute(),
        rawPayload: ['status' => 'finished'],
    );

    // Guards must hold: still exactly one cashback entry and one review notification
    expect(WalletEntry::query()->where('reference', "cashback:{$order->id}")->count())->toBe(1);
    Notification::assertSentTimes(ReviewInviteNotification::class, 1);
});

it('does not fire completion effects on non-completing observations (Rule 6)', function (): void {
    Notification::fake();
    config()->set('store.features.loyalty_enabled', true);
    loyaltySeedTiers();

    [$order, $item, $job] = createObservationContext(
        orderStatus: OrderStatus::Received,
        itemStatus: OrderItemStatus::Received,
    );

    loyaltySettledPayment($order, PaymentStatus::Paid, 20_000);

    // State transitions item and order from Received to InProgress
    $state = new TranslatedState(
        status: OrderStatus::InProgress,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'entered',
    );

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state,
        observedAt: now(),
        rawPayload: ['status' => 'entered'],
    );

    expect($order->fresh()->status)->toBe(OrderStatus::InProgress)
        ->and($order->fresh()->review_invited_at)->toBeNull();

    expect(WalletEntry::query()->where('reference', "cashback:{$order->id}")->count())->toBe(0);
    Notification::assertNothingSent();
});

it('masks customer email in raw payload before storing in the observation column (Rule 7)', function (): void {
    [$order, $item, $job] = createObservationContext();

    $rawPayload = [
        'orderID' => 'FFT-123456',
        'status' => 'entered',
        'ea_email' => 'fifa_customer@example.com',
        'account_details' => [
            'email' => 'nested_account@ea.origin.com',
            'state' => 'challenge_running',
        ],
        'note' => 'login failed for fifa_customer@example.com',
        'contact' => 'Customer <fifa_customer@example.com>',
        'amount' => 250,
    ];

    $state = new TranslatedState(
        status: OrderStatus::InProgress,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'entered',
        coinsDelivered: 250_000,
    );

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state,
        observedAt: now(),
        rawPayload: $rawPayload,
    );

    $freshJob = $job->fresh();
    $stored = $freshJob->observation;

    // Emails must be masked to first character plus fixed dots with domain preserved
    expect($stored['ea_email'])->toBe('f...@example.com')
        ->and($stored['account_details']['email'])->toBe('n...@ea.origin.com')
        ->and($stored['note'])->toBe('login failed for f...@example.com')
        ->and($stored['contact'])->toBe('Customer <f...@example.com>')
        ->and($stored['status'])->toBe('entered')
        ->and($stored['orderID'])->toBe('FFT-123456')
        ->and($stored['amount'])->toBe(250);
});

it('re-opens polling without un-completing earlier phase when challenge observation arrives on completed coins job (Rule 8)', function (): void {
    $completedAt = CarbonImmutable::parse('2026-09-12 11:00:00');

    [$order, $item, $job] = createObservationContext(
        orderStatus: OrderStatus::InProgress,
        itemStatus: OrderItemStatus::InProgress,
        jobAttributes: [
            'delivery_phase' => DeliveryPhase::Coins,
            'status' => FulfillmentStatus::Completed,
            'completed_at' => $completedAt,
            'next_poll_at' => null,
            'coins_delivered' => 250_000,
            'coins_ordered' => 250_000,
        ],
    );

    FulfillmentPlacement::query()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'CHALLENGE-SUPPLIER-1',
        'idempotency_key' => 'placement-test-chal-1',
        'placed_at' => now(),
    ]);

    $state = new TranslatedState(
        status: OrderStatus::InProgress,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'challenge_running',
        coinsDelivered: null, // Challenge payload does not carry coins
        coinsOrdered: null,
        challengesSolved: 3,
        challengesRequested: 10,
    );

    $now = CarbonImmutable::parse('2026-09-12 12:00:00');
    Carbon::setTestNow($now);

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state,
        observedAt: $now,
        rawPayload: [
            'delivery_phase' => 'challenge',
            'state' => 'challenge_running',
            'challenges' => ['solved' => 3, 'requested' => 10],
        ],
    );

    $freshJob = $job->fresh();

    // Polling re-opened
    expect($freshJob->completed_at)->toBeNull()
        ->and($freshJob->next_poll_at?->toDateTimeString())->toBe($now->toDateTimeString())
        ->and($freshJob->status)->toBe(FulfillmentStatus::InProgress)
        ->and($freshJob->delivery_phase)->toBe(DeliveryPhase::Challenge);

    // Coins progress counters from previous phase were NOT erased
    expect($freshJob->coins_delivered)->toBe(250_000)
        ->and($freshJob->coins_ordered)->toBe(250_000)
        ->and($freshJob->challenges_solved)->toBe(3)
        ->and($freshJob->challenges_requested)->toBe(10);
});

it('never moves a completed order backwards when a challenge observation arrives (Rule 2 + Rule 8)', function (): void {
    $completedAt = CarbonImmutable::parse('2026-09-12 11:00:00');

    [$order, $item, $job] = createObservationContext(
        orderStatus: OrderStatus::Completed,
        itemStatus: OrderItemStatus::Completed,
        orderAttributes: [
            'completed_at' => $completedAt,
        ],
        jobAttributes: [
            'delivery_phase' => DeliveryPhase::Coins,
            'status' => FulfillmentStatus::Completed,
            'completed_at' => $completedAt,
            'next_poll_at' => null,
            'coins_delivered' => 250_000,
            'coins_ordered' => 250_000,
        ],
    );

    FulfillmentPlacement::query()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'CHALLENGE-SUPPLIER-2',
        'idempotency_key' => 'placement-test-chal-2',
        'placed_at' => now(),
    ]);

    $state = new TranslatedState(
        status: OrderStatus::InProgress,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'challenge_running',
        challengesSolved: 2,
        challengesRequested: 10,
    );

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state,
        observedAt: now(),
        rawPayload: [
            'delivery_phase' => 'challenge',
            'state' => 'challenge_running',
        ],
    );

    // Rule 2 prevents completed order and its items from moving backwards
    expect($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($item->fresh()->status)->toBe(OrderItemStatus::Completed)
        ->and(OrderStatusHistory::query()->count())->toBe(0);
});
