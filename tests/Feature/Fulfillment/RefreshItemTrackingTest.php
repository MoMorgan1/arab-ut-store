<?php

use App\Account\Presenters\ItemTracking;
use App\Actions\Fulfillment\RefreshItemTracking;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.suppliers.fft.base_url', 'https://fft.example.test');
    config()->set('services.suppliers.fft.api_user', 'store-fft');
    config()->set('services.suppliers.fft.api_key', 'fft-key');
    Http::preventStrayRequests();
});

/**
 * @param  array<string, mixed>  $jobAttributes
 * @return array{0: Order, 1: OrderItem, 2: FulfillmentJob}
 */
function createTrackingContext(
    ServiceType $serviceType = ServiceType::Sbc,
    DeliveryPhase $deliveryPhase = DeliveryPhase::Challenge,
    OrderStatus $orderStatus = OrderStatus::InProgress,
    OrderItemStatus $itemStatus = OrderItemStatus::InProgress,
    array $jobAttributes = [],
): array {
    $user = User::factory()->create();

    $order = Order::factory()->for($user)->create([
        'order_number' => 'UT-'.fake()->unique()->numerify('########'),
        'status' => $orderStatus,
        'placed_at' => now(),
    ]);

    $item = OrderItem::factory()->for($order)->create([
        'name_ar' => 'خدمة تحديات',
        'name_en' => 'SBC Service',
        'service_type' => $serviceType,
        'platform' => Platform::PlayStation,
        'status' => $itemStatus,
    ]);

    $job = FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'fft-job-'.$item->id,
        'delivery_phase' => $deliveryPhase,
        ...$jobAttributes,
    ]);

    return [$order, $item, $job];
}

test('test 14: a challenge-phase job reads through observeChallenges with the placements ids', function (): void {
    $challengeId1 = '1803b7a6-0000-0000-0000-00000064265f';
    $challengeId2 = '2b8e38f6-1111-2222-3333-444455556666';

    [$order, $item, $job] = createTrackingContext(
        serviceType: ServiceType::Sbc,
        deliveryPhase: DeliveryPhase::Challenge,
    );

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => [$challengeId1, $challengeId2],
        'idempotency_key' => 'placement-test-14',
        'placed_at' => now(),
    ]);

    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response([
            $challengeId1 => [
                'sbcStatus' => 'solvingChallenge',
                'challengesDone' => 3,
                'totalChallenges' => 7,
                'timesSolved' => 1,
                'timesToSolve' => 2,
            ],
            $challengeId2 => [
                'sbcStatus' => 'finished',
                'challengesDone' => 7,
                'totalChallenges' => 7,
                'timesSolved' => 2,
                'timesToSolve' => 2,
            ],
        ]),
    ]);

    $tracking = app(RefreshItemTracking::class)->execute($item, 'en');

    Http::assertSent(function (Request $request) use ($challengeId1, $challengeId2): bool {
        $data = $request->data();

        return $request->url() === 'https://fft.example.test/sbcStatusBulkAPI'
            && $request->method() === 'POST'
            && $data['apiUser'] === 'store-fft'
            && $data['apiKey'] === 'fft-key'
            && $data['sbcIDs'] === [$challengeId1, $challengeId2];
    });

    expect($tracking)->not->toBeNull()
        ->and($tracking['phase'])->toBe('challenge')
        ->and($tracking['progress'])->toBe([
            'coinsDelivered' => null,
            'coinsOrdered' => null,
            'squadsDone' => 3,
            'squadsTotal' => 7,
            'solvesDone' => 1,
            'solvesTotal' => 2,
        ]);
});

test('test 15: a coins-phase job still calls observe and not the bulk endpoint', function (): void {
    [$order, $item, $job] = createTrackingContext(
        serviceType: ServiceType::Coins,
        deliveryPhase: DeliveryPhase::Coins,
    );

    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response([
            'status' => 'entered',
            'amount' => 200_000,
            'amountOrdered' => 500_000,
        ]),
    ]);

    $tracking = app(RefreshItemTracking::class)->execute($item, 'en');

    Http::assertSent(function (Request $request) use ($job): bool {
        $data = $request->data();

        return $request->url() === 'https://fft.example.test/orderStatusAPI'
            && $request->method() === 'POST'
            && $data['orderID'] === $job->supplier_order_id;
    });

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), 'sbcStatusBulkAPI');
    });

    expect($tracking)->not->toBeNull()
        ->and($tracking['phase'])->toBe('coins');
});

test('test 16: a challenge placement with no stored ids returns the stored tracking, calls no supplier, and does not throw', function (): void {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'No challenge IDs found')
                && isset($context['job_id']);
        });

    [$order, $item, $job] = createTrackingContext(
        serviceType: ServiceType::Sbc,
        deliveryPhase: DeliveryPhase::Challenge,
        jobAttributes: [
            'squads_done' => 1,
            'squads_total' => 5,
            'solves_done' => null,
            'solves_total' => null,
        ],
    );

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => [],
        'idempotency_key' => 'placement-test-16',
        'placed_at' => now(),
    ]);

    Http::fake();

    $expected = ItemTracking::for($item, 'en');

    $tracking = app(RefreshItemTracking::class)->execute($item, 'en');

    Http::assertNothingSent();

    expect($tracking)->toBe($expected)
        ->and($tracking['progress']['squadsDone'])->toBe(1)
        ->and($tracking['progress']['squadsTotal'])->toBe(5);
});

test('test 17: SupplierUnavailable from the bulk read returns the stored tracking unchanged', function (): void {
    $challengeId = '1803b7a6-0000-0000-0000-00000064265f';

    [$order, $item, $job] = createTrackingContext(
        serviceType: ServiceType::Sbc,
        deliveryPhase: DeliveryPhase::Challenge,
        jobAttributes: [
            'squads_done' => 2,
            'squads_total' => 4,
            'solves_done' => 0,
            'solves_total' => 1,
        ],
    );

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => [$challengeId],
        'idempotency_key' => 'placement-test-17',
        'placed_at' => now(),
    ]);

    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response('Gateway Timeout', 504),
    ]);

    $expected = ItemTracking::for($item, 'en');

    $tracking = app(RefreshItemTracking::class)->execute($item, 'en');

    expect($tracking)->toBe($expected)
        ->and($tracking['progress']['squadsDone'])->toBe(2)
        ->and($tracking['progress']['squadsTotal'])->toBe(4);
});

test('test 18: a requested id missing from response while every returned entry is finished leaves order InProgress and does not fire completion', function (): void {
    $challengeId1 = '1803b7a6-0000-0000-0000-00000064265f';
    $challengeId2 = '2b8e38f6-1111-2222-3333-444455556666';

    [$order, $item, $job] = createTrackingContext(
        serviceType: ServiceType::Sbc,
        deliveryPhase: DeliveryPhase::Challenge,
    );

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => [$challengeId1, $challengeId2],
        'idempotency_key' => 'placement-test-18',
        'placed_at' => now(),
    ]);

    // Supplier answers only challengeId1 (finished); challengeId2 is absent
    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response([
            $challengeId1 => [
                'sbcStatus' => 'finished',
                'challengesDone' => 7,
                'totalChallenges' => 7,
                'timesSolved' => 2,
                'timesToSolve' => 2,
            ],
        ]),
    ]);

    $tracking = app(RefreshItemTracking::class)->execute($item, 'en');

    expect($order->fresh()->status)->toBe(OrderStatus::InProgress)
        ->and($order->fresh()->completed_at)->toBeNull()
        ->and($item->fresh()->status)->toBe(OrderItemStatus::InProgress)
        ->and($job->fresh()->status)->toBe(FulfillmentStatus::InProgress)
        ->and($job->fresh()->completed_at)->toBeNull();
});

test('test 19: an empty response on a completed job leaves completed_at, job status, and stored observation untouched, and calls no writer', function (): void {
    $challengeId = '1803b7a6-0000-0000-0000-00000064265f';
    $completedAt = CarbonImmutable::parse('2026-09-12 10:00:00');
    $initialObservation = [$challengeId => ['sbcStatus' => 'finished', 'challengesDone' => 7, 'totalChallenges' => 7]];

    [$order, $item, $job] = createTrackingContext(
        serviceType: ServiceType::Sbc,
        deliveryPhase: DeliveryPhase::Challenge,
        jobAttributes: [
            'status' => FulfillmentStatus::Completed,
            'completed_at' => $completedAt,
            'observation' => $initialObservation,
        ],
    );

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => [$challengeId],
        'idempotency_key' => 'placement-test-19',
        'placed_at' => now(),
    ]);

    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response([]),
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($job, $challengeId): bool {
            return str_contains($message, 'Bulk challenge response contained no requested challenge IDs')
                && ($context['job_id'] ?? null) === $job->id
                && in_array($challengeId, $context['challenge_ids'] ?? [], true);
        });

    $expected = ItemTracking::for($item, 'en');
    $tracking = app(RefreshItemTracking::class)->execute($item, 'en');

    $freshJob = $job->fresh();
    expect($tracking)->toBe($expected)
        ->and($freshJob->completed_at?->toDateTimeString())->toBe($completedAt->toDateTimeString())
        ->and($freshJob->status)->toBe(FulfillmentStatus::Completed)
        ->and($freshJob->observation)->toBe($initialObservation);
});

test('test 20: a response missing one of three ids still applies the counters of the active returned entry', function (): void {
    $challengeId1 = '1803b7a6-0000-0000-0000-00000064265f';
    $challengeId2 = '2b8e38f6-1111-2222-3333-444455556666';
    $challengeId3 = '3c9f49a7-2222-3333-4444-555566667777';

    [$order, $item, $job] = createTrackingContext(
        serviceType: ServiceType::Sbc,
        deliveryPhase: DeliveryPhase::Challenge,
    );

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => [$challengeId1, $challengeId2, $challengeId3],
        'idempotency_key' => 'placement-test-20',
        'placed_at' => now(),
    ]);

    // Supplier answers id1 (finished) and id2 (solving); id3 is missing
    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response([
            $challengeId1 => [
                'sbcStatus' => 'finished',
                'challengesDone' => 7,
                'totalChallenges' => 7,
                'timesSolved' => 2,
                'timesToSolve' => 2,
            ],
            $challengeId2 => [
                'sbcStatus' => 'solvingChallenge',
                'challengesDone' => 4,
                'totalChallenges' => 7,
                'timesSolved' => 1,
                'timesToSolve' => 2,
            ],
        ]),
    ]);

    $tracking = app(RefreshItemTracking::class)->execute($item, 'en');

    $freshJob = $job->fresh();
    expect($tracking)->not->toBeNull()
        ->and($tracking['progress'])->toBe([
            'coinsDelivered' => null,
            'coinsOrdered' => null,
            'squadsDone' => 4,
            'squadsTotal' => 7,
            'solvesDone' => 1,
            'solvesTotal' => 2,
        ])
        ->and($freshJob->squads_done)->toBe(4)
        ->and($freshJob->squads_total)->toBe(7)
        ->and($freshJob->solves_done)->toBe(1)
        ->and($freshJob->solves_total)->toBe(2)
        ->and($order->fresh()->status)->toBe(OrderStatus::InProgress);
});
