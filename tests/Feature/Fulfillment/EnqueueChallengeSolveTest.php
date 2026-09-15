<?php

use App\Actions\Fulfillment\ApplySupplierObservation;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Suppliers\Translation\TranslatedState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coinsLanded(): TranslatedState
{
    return new TranslatedState(
        status: OrderStatus::Completed,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'finished',
        coinsDelivered: 1_000_000,
    );
}

function observe(FulfillmentJob $job, ?TranslatedState $state = null): void
{
    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: $state ?? coinsLanded(),
        observedAt: now(),
        rawPayload: ['status' => 'finished'],
    );
}

function inProgressOrder(): Order
{
    return Order::factory()->create(['status' => OrderStatus::InProgress, 'paid_at' => now()]);
}

test('a challenge whose coins have landed queues one challenge.ready row, committed with the completion', function (): void {
    $order = inProgressOrder();
    $item = challengeItem($order);
    $job = fundedChallengeJob($item);

    observe($job);

    $event = IntegrationEvent::query()->where('event_type', 'challenge.ready')->sole();

    expect($job->fresh()->status)->toBe(FulfillmentStatus::Completed)
        ->and($event->aggregate_type)->toBe('order_item')
        ->and($event->aggregate_id)->toBe($item->public_id)
        ->and($event->schema_version)->toBe(1)
        ->and($event->status)->toBe('pending')
        ->and($event->idempotency_key)->toBe('challenge-ready:'.$item->id)
        ->and($event->payload)->toBe([
            'order_public_id' => $order->public_id,
            'order_number' => $order->order_number,
            'order_item_public_id' => $item->public_id,
        ]);

    // The row names the item and nothing more. The supplier's set id is
    // matched whole: a bare '412' also occurs inside a random ULID now and
    // then, which failed this test on unrelated changes.
    expect(json_encode($event->payload, JSON_THROW_ON_ERROR))
        ->not->toContain('ea_')
        ->not->toContain($item->productVariant->product->external_id);

    // A repeated "finished" reading queues nothing new.
    observe($job->fresh());
    expect(IntegrationEvent::query()->where('event_type', 'challenge.ready')->count())->toBe(1);
});

test('nothing is queued for a coins item, for a challenge already solved, or while the coins are still moving', function (): void {
    $order = inProgressOrder();

    // A plain coins item completing.
    $coinsItem = coinsItem($order, ['status' => OrderItemStatus::InProgress]);
    $coinsJob = FulfillmentJob::factory()->create([
        'order_item_id' => $coinsItem->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'FFT-1',
        'delivery_phase' => DeliveryPhase::Coins,
    ]);
    observe($coinsJob);

    // A challenge whose solve was already pasted by staff.
    $solved = challengeItem($order);
    $solvedJob = fundedChallengeJob($solved, [], Supplier::Fft, 'FFT-2');
    FulfillmentPlacement::query()->create([
        'fulfillment_job_id' => $solvedJob->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => '0a1b2c3d-0000-4000-8000-000000000001',
        'supplier_challenge_ids' => ['0a1b2c3d-0000-4000-8000-000000000001'],
        'idempotency_key' => 'fulfillment-placement:'.$solved->public_id.':challenge',
        'placed_at' => now(),
    ]);
    observe($solvedJob);

    // A challenge still being funded.
    $moving = challengeItem($order);
    $movingJob = fundedChallengeJob($moving, [], Supplier::Utt, '574340');
    observe($movingJob, new TranslatedState(
        status: OrderStatus::InProgress,
        holdReason: null,
        allowedActions: [],
        supported: true,
        observedState: 'entered',
        coinsDelivered: 200_000,
    ));

    expect(IntegrationEvent::query()->where('event_type', 'challenge.ready')->count())->toBe(0);
});

test('an admin hold on the item keeps the solve from being queued', function (): void {
    $order = inProgressOrder();
    $item = challengeItem($order, ['status' => OrderItemStatus::WaitingForCustomer]);
    OrderStatusHistory::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'actor_user_id' => null,
        'status' => OrderStatusHistoryStatus::WaitingForCustomer,
        'metadata' => ['source' => 'admin'],
    ]);
    $job = fundedChallengeJob($item);

    observe($job);

    expect(IntegrationEvent::query()->where('event_type', 'challenge.ready')->count())->toBe(0)
        ->and(OrderItem::query()->find($item->id)?->status)->toBe(OrderItemStatus::WaitingForCustomer);
});
