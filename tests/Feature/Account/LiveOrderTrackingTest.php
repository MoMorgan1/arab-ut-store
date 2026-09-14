<?php

use App\Account\Presenters\ItemTracking;
use App\Enums\ChallengeState;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\HoldTone;
use App\Enums\OrderHoldReason;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Enums\TrackingPresentation;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Suppliers\Translation\SbcStatusPresentation;
use App\Suppliers\Translation\SupplierStateTranslator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
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

/**
 * A placed SBC item whose challenge observation reports one sbcStatus per
 * challenge id, returning the presenter payload for the given locale.
 *
 * @param  list<string>  $sbcStatuses
 */
function challengeTracking(array $sbcStatuses, ?OrderStatus $orderStatus = null, string $locale = 'en'): array
{
    $owner = User::factory()->create();
    $order = trackingOrder($owner, $orderStatus ?? OrderStatus::InProgress);
    $item = trackingItem($order, ServiceType::Sbc);

    $ids = [];
    $observation = [];
    foreach (array_values($sbcStatuses) as $i => $status) {
        $id = sprintf('1803b7a6-0000-0000-0000-%012x', $i + 1);
        $ids[] = $id;
        $observation[$id] = ['sbcStatus' => $status];
    }

    $job = trackingJob($item, [
        'delivery_phase' => DeliveryPhase::Challenge,
        'observation' => $observation,
    ]);

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => $ids,
        'idempotency_key' => 'placement-'.fake()->unique()->word(),
        'placed_at' => now(),
    ]);

    return ItemTracking::for($item, $locale);
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
        'squads_done' => null,
        'squads_total' => null,
        'solves_done' => null,
        'solves_total' => null,
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
        'squads_done' => 2,
        'squads_total' => 5,
        'solves_done' => 1,
        'solves_total' => 3,
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking.progress', [
            'coinsDelivered' => 50_000,
            'coinsOrdered' => 100_000,
            'squadsDone' => 2,
            'squadsTotal' => 5,
            'solvesDone' => 1,
            'solvesTotal' => 3,
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
        'squads_done' => null,
        'squads_total' => null,
        'solves_done' => null,
        'solves_total' => null,
    ]);

    $response = $this->actingAs($owner)
        ->get('/en/my-account/orders/'.$order->order_number)
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking.progress', [
            'coinsDelivered' => 0,
            'coinsOrdered' => 250_000,
            'squadsDone' => null,
            'squadsTotal' => null,
            'solvesDone' => null,
            'solvesTotal' => null,
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

    // Compared against the translator rather than a transcribed sentence: the
    // rule under test is that holdMessage carries the localised text for the
    // reason, and the copy itself changes whenever the owner rewrites it.
    // Arabic locale
    $arResponse = $this->actingAs($owner)
        ->get('/my-account/orders/'.$order->order_number)
        ->assertOk();

    $arResponse->assertInertia(fn (Assert $page) => $page
        ->where('order.items.0.tracking.holdReason', 'backup_codes')
        ->where(
            'order.items.0.tracking.holdMessage',
            trans('orders.hold_reasons.backup_codes', locale: 'ar')
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
            trans('orders.hold_reasons.backup_codes', locale: 'en')
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
        ->where('order.items.0.tracking.phase', null)
        ->where('order.items.0.tracking.supported', true)
        ->where('order.items.0.tracking.observedAt', '2026-09-12T11:00:00+00:00')
        ->where('order.items.0.tracking.actions', ['resume'])
        ->where('order.items.0.tracking.progress', [
            'coinsDelivered' => 200_000,
            'coinsOrdered' => 500_000,
            'squadsDone' => null,
            'squadsTotal' => null,
            'solvesDone' => null,
            'solvesTotal' => null,
        ])
        ->where('order.items.1.id', $manualItem->public_id)
        ->has('order.items.0.tracking')
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
        'presentation' => TrackingPresentation::NeedsReview,
        'hold_tone' => HoldTone::Action,
        'allowed_actions' => ['retry_challenge'],
        'observation_supported' => true,
        'observed_at' => CarbonImmutable::parse('2026-09-12 15:00:00', 'UTC'),
        'coins_delivered' => 100_000,
        'coins_ordered' => 200_000,
        'squads_done' => 1,
        'squads_total' => 1,
        'solves_done' => null,
        'solves_total' => null,
    ]);

    $tracking = ItemTracking::for($item, 'en');

    expect($tracking)->toBe([
        'kind' => 'coins',
        'phase' => 'coins',
        'presentation' => 'needs_review',
        'headline' => 'Action Required',
        'subline' => 'Please check the details below',
        'holdReason' => 'ea_servers',
        'holdMessage' => trans('orders.hold_reasons.ea_servers', locale: 'en'),
        'holdTone' => 'action',
        'completedAt' => null,
        'actions' => ['retry_challenge'],
        'supported' => true,
        'observedAt' => '2026-09-12T15:00:00+00:00',
        'accountCoins' => [
            'amount' => null,
            'state' => 'unknown',
        ],
        'progress' => [
            'coinsDelivered' => 100_000,
            'coinsOrdered' => 200_000,
            'squadsDone' => 1,
            'squadsTotal' => 1,
            'solvesDone' => null,
            'solvesTotal' => null,
        ],
        'challenges' => null,
        'coverage' => null,
        'workStarted' => false,
        'credentialsPending' => false,
    ]);
});

test('accountCoins distinguishes known amount, preparing (-1), and unknown', function (mixed $coinsCust, ?int $expectedAmount, string $expectedState): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, [
        'observation' => $coinsCust !== null ? ['coinsCustomerAccount' => $coinsCust] : [],
    ]);

    $tracking = ItemTracking::for($item, 'en');

    expect($tracking['accountCoins'])->toBe([
        'amount' => $expectedAmount,
        'state' => $expectedState,
    ]);
})->with([
    'known balance' => [150_000, 150_000, 'known'],
    'zero balance' => [0, 0, 'known'],
    'preparing' => [-1, null, 'preparing'],
    'absent' => [null, null, 'unknown'],
]);

test('coinsOrdered falls back to item configuration when fulfillment job has no observation', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);
    $item->update([
        'configuration' => [
            'coins_quantity' => 750_000,
        ],
    ]);

    trackingJob($item, [
        'coins_ordered' => null,
        'coins_delivered' => null,
    ]);

    $tracking = ItemTracking::for($item, 'en');

    expect($tracking['progress']['coinsOrdered'])->toBe(750_000)
        ->and($tracking['progress']['coinsDelivered'])->toBeNull();
});

test('challenge list target indices are 0-based integer positions and un-returned challenges are marked unknown', function (): void {
    $challengeId1 = '1803b7a6-0000-0000-0000-00000064265f';
    $challengeId2 = '2b8e38f6-1111-2222-3333-444455556666';
    $challengeId3 = '3c9f49a7-2222-3333-4444-555566667777';

    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Sbc);

    $job = trackingJob($item, [
        'delivery_phase' => DeliveryPhase::Challenge,
        'observation' => [
            $challengeId1 => [
                'sbcStatus' => 'finished',
                'challengesDone' => 7,
                'totalChallenges' => 7,
                'timesSolved' => 2,
                'timesToSolve' => 2,
                'costCoins' => 450_000,
            ],
            // challengeId2 is not returned in observation
            $challengeId3 => [
                'sbcStatus' => 'solvingChallenge',
                'challengesDone' => 3,
                'totalChallenges' => 7,
                'timesSolved' => 1,
                'timesToSolve' => 2,
                'costCoins' => 120_000,
            ],
        ],
    ]);

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => [$challengeId1, $challengeId2, $challengeId3],
        'idempotency_key' => 'placement-test-challenge-list',
        'placed_at' => now(),
    ]);

    $tracking = ItemTracking::for($item, 'en');

    expect($tracking['kind'])->toBe('challenge')
        ->and($tracking['coverage'])->toBe(['answered' => 2, 'requested' => 3])
        ->and($tracking['challenges'])->toHaveCount(3)
        // Target 0: challengeId1
        ->and($tracking['challenges'][0]['target'])->toBe(0)
        ->and($tracking['challenges'][0]['state'])->toBe('done')
        ->and($tracking['challenges'][0]['stateLabel'])->toBe('Completed')
        ->and($tracking['challenges'][0]['squads'])->toBe(['done' => 7, 'total' => 7])
        ->and($tracking['challenges'][0]['solves'])->toBe(['done' => 2, 'total' => 2])
        ->and($tracking['challenges'][0]['coinsUsed'])->toBe(450_000)
        // Target 1: challengeId2 (un-returned)
        ->and($tracking['challenges'][1]['target'])->toBe(1)
        ->and($tracking['challenges'][1]['state'])->toBe('unknown')
        ->and($tracking['challenges'][1]['stateLabel'])->toBe('Unknown')
        ->and($tracking['challenges'][1]['squads'])->toBe(['done' => null, 'total' => null])
        ->and($tracking['challenges'][1]['solves'])->toBe(['done' => null, 'total' => null])
        ->and($tracking['challenges'][1]['coinsUsed'])->toBeNull()
        ->and($tracking['challenges'][1]['actions'])->toBe([])
        // Target 2: challengeId3
        ->and($tracking['challenges'][2]['target'])->toBe(2)
        ->and($tracking['challenges'][2]['state'])->toBe('solving')
        ->and($tracking['challenges'][2]['stateLabel'])->toBe('Solving squad')
        ->and($tracking['challenges'][2]['squads'])->toBe(['done' => 3, 'total' => 7])
        ->and($tracking['challenges'][2]['solves'])->toBe(['done' => 1, 'total' => 2])
        ->and($tracking['challenges'][2]['coinsUsed'])->toBe(120_000);
});

test('challenge card surfaces curated holdReason, holdMessage, and holdTone for SBC entry status', function (): void {
    $challengeId1 = '1803b7a6-0000-0000-0000-00000064265f';
    $challengeId2 = '2b8e38f6-1111-2222-3333-444455556666';
    $challengeId3 = '3c9f49a7-2222-3333-4444-555566667777';
    $challengeId4 = '4d0a5ab8-3333-4444-5555-666677778888';

    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Sbc);

    $job = trackingJob($item, [
        'delivery_phase' => DeliveryPhase::Challenge,
        'observation' => [
            $challengeId1 => [
                'sbcStatus' => 'WrongUserPass',
            ],
            $challengeId2 => [
                'sbcStatus' => 'TempbanCooldown',
            ],
            $challengeId3 => [
                'sbcStatus' => 'finished',
            ],
            $challengeId4 => [
                'sbcStatus' => 'WrongBA',
            ],
        ],
    ]);

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => [$challengeId1, $challengeId2, $challengeId3, $challengeId4],
        'idempotency_key' => 'placement-test-defect-3c',
        'placed_at' => now(),
    ]);

    $trackingEn = ItemTracking::for($item, 'en');

    // The message is asserted against the reason's own copy rather than a literal: the
    // point of the field is that the card carries the reason's message, not that the
    // copy never changes.
    // Target 0: WrongUserPass - the customer must act, and on which detail.
    expect($trackingEn['challenges'][0]['holdReason'])->toBe('credentials')
        ->and($trackingEn['challenges'][0]['holdTone'])->toBe('action')
        ->and($trackingEn['challenges'][0]['holdMessage'])->toBe(OrderHoldReason::Credentials->message('en'))
        // Target 1: TempbanCooldown - a wait, so informational, and a different reason.
        ->and($trackingEn['challenges'][1]['holdReason'])->toBe('paused')
        ->and($trackingEn['challenges'][1]['holdTone'])->toBe('info')
        ->and($trackingEn['challenges'][1]['holdMessage'])->toBe(OrderHoldReason::Paused->message('en'))
        // Target 2: finished -> nulls
        ->and($trackingEn['challenges'][2]['holdReason'])->toBeNull()
        ->and($trackingEn['challenges'][2]['holdTone'])->toBeNull()
        ->and($trackingEn['challenges'][2]['holdMessage'])->toBeNull();

    // Target 3: WrongBA. This is the case the field exists for - it curates to the same
    // chip as WrongUserPass, because the customer does not read our status codes, but the
    // two ask them to fix different things and the card has to say which.
    expect($trackingEn['challenges'][3]['state'])->toBe($trackingEn['challenges'][0]['state'])
        ->and($trackingEn['challenges'][3]['holdReason'])->toBe('backup_codes')
        ->and($trackingEn['challenges'][3]['holdReason'])->not->toBe($trackingEn['challenges'][0]['holdReason'])
        ->and($trackingEn['challenges'][3]['holdMessage'])->not->toBe($trackingEn['challenges'][0]['holdMessage']);

    $trackingAr = ItemTracking::for($item, 'ar');
    expect($trackingAr['challenges'][0]['holdReason'])->toBe('credentials')
        ->and($trackingAr['challenges'][0]['holdTone'])->toBe('action')
        ->and($trackingAr['challenges'][0]['holdMessage'])->toBe(OrderHoldReason::Credentials->message('ar'))
        ->and($trackingAr['challenges'][0]['holdMessage'])->not->toBe($trackingEn['challenges'][0]['holdMessage']);
});

test('defect 5: terminal order or item hides challenge card actions', function (OrderStatus $orderStatus, OrderItemStatus $itemStatus): void {
    $challengeId = '1803b7a6-0000-0000-0000-00000064265f';

    $owner = User::factory()->create();
    $order = trackingOrder($owner, $orderStatus);
    $item = trackingItem($order, ServiceType::Sbc, $itemStatus);

    $job = trackingJob($item, [
        'delivery_phase' => DeliveryPhase::Challenge,
        'observation' => [
            $challengeId => [
                'sbcStatus' => 'WrongUserPass',
            ],
        ],
    ]);

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => [$challengeId],
        'idempotency_key' => 'placement-test-defect-5-'.fake()->unique()->word(),
        'placed_at' => now(),
    ]);

    $tracking = ItemTracking::for($item, 'en');

    expect($tracking['challenges'][0]['actions'])->toBe([]);
})->with([
    'order completed' => [OrderStatus::Completed, OrderItemStatus::InProgress],
    'order cancelled' => [OrderStatus::Cancelled, OrderItemStatus::InProgress],
    'order refunded' => [OrderStatus::Refunded, OrderItemStatus::InProgress],
    'item completed' => [OrderStatus::InProgress, OrderItemStatus::Completed],
    'item cancelled' => [OrderStatus::InProgress, OrderItemStatus::Cancelled],
    'item refunded' => [OrderStatus::InProgress, OrderItemStatus::Refunded],
]);

test('defect 7: workStarted dynamic boolean matches ui.js predicate', function (array $observation, ?CarbonImmutable $completedAt, bool $expected): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, [
        'observation' => $observation,
        'completed_at' => $completedAt,
    ]);

    $tracking = ItemTracking::for($item, 'en');

    expect($tracking['workStarted'])->toBe($expected);
})->with([
    'transfersinprogress status' => [['status' => 'transfersinprogress'], null, true],
    'transfersinprogress with simplified error' => [['status' => 'transfersinprogress', 'simplifiedStatus' => 'error'], null, false],
    'userPassVerified accountCheck' => [['accountCheck' => 'userPassVerified'], null, true],
    'transfersInProgress economyState' => [['economyState' => 'transfersInProgress'], null, true],
    'finished status' => [['status' => 'finished'], null, true],
    'completed status' => [['status' => 'completed'], null, true],
    'completed_at set' => [[], CarbonImmutable::now(), true],
    'idle/queued without triggers' => [['status' => 'queued', 'accountCheck' => '', 'economyState' => 'ready'], null, false],
    'empty observation' => [[], null, false],
]);

test('a terminal order reports its own ending, not the last thing the supplier said', function (
    OrderStatus $orderStatus,
    OrderItemStatus $itemStatus,
    TrackingPresentation $expected,
): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner, $orderStatus);
    $item = trackingItem($order, ServiceType::Coins, $itemStatus);

    // The job still holds everything the supplier last said, because nothing clears it
    // when an order ends: the refresh refuses a terminal order before it reaches the
    // translator, and neither the admin transition nor the refund touches the job.
    trackingJob($item, [
        'presentation' => TrackingPresentation::Transferring,
        'hold_reason' => OrderHoldReason::Credentials,
        'hold_tone' => HoldTone::Action,
        'allowed_actions' => ['edit_credentials', 'resume'],
        'coins_delivered' => 40_000,
        'coins_ordered' => 100_000,
    ]);

    $tracking = ItemTracking::for($item, 'ar');

    expect($tracking['presentation'])->toBe($expected->value)
        ->and($tracking['headline'])->toBe($expected->headline('ar'))
        ->and($tracking['holdReason'])->toBeNull()
        ->and($tracking['holdMessage'])->toBeNull()
        ->and($tracking['holdTone'])->toBeNull()
        ->and($tracking['actions'])->toBe([])
        // The counters are facts about what happened and survive; only the invitations
        // to act are removed.
        ->and($tracking['progress']['coinsDelivered'])->toBe(40_000)
        ->and($tracking['progress']['coinsOrdered'])->toBe(100_000);
})->with([
    'order cancelled' => [OrderStatus::Cancelled, OrderItemStatus::InProgress, TrackingPresentation::Cancelled],
    'order refunded' => [OrderStatus::Refunded, OrderItemStatus::InProgress, TrackingPresentation::Refunded],
    'order completed' => [OrderStatus::Completed, OrderItemStatus::InProgress, TrackingPresentation::Completed],
    'item cancelled' => [OrderStatus::InProgress, OrderItemStatus::Cancelled, TrackingPresentation::Cancelled],
    'item refunded' => [OrderStatus::InProgress, OrderItemStatus::Refunded, TrackingPresentation::Refunded],
    'item completed' => [OrderStatus::InProgress, OrderItemStatus::Completed, TrackingPresentation::Completed],
]);

test('a live order still reports the supplier state and its actions', function (): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, [
        'presentation' => TrackingPresentation::Transferring,
        'hold_reason' => OrderHoldReason::Credentials,
        'hold_tone' => HoldTone::Action,
        'allowed_actions' => ['edit_credentials', 'resume'],
    ]);

    $tracking = ItemTracking::for($item, 'ar');

    expect($tracking['presentation'])->toBe('transferring')
        ->and($tracking['holdReason'])->toBe('credentials')
        ->and($tracking['holdTone'])->toBe('action')
        ->and($tracking['actions'])->toBe(['edit_credentials', 'resume']);
});

test('a terminal order stops asking the customer to fix anything, on the cards too', function (): void {
    $challengeId = '5e1b6bc9-4444-5555-6666-777788889999';

    $owner = User::factory()->create();
    $order = trackingOrder($owner, OrderStatus::Cancelled);
    $item = trackingItem($order, ServiceType::Sbc);

    $job = trackingJob($item, [
        'delivery_phase' => DeliveryPhase::Challenge,
        'observation' => [$challengeId => ['sbcStatus' => 'WrongUserPass']],
    ]);

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => [$challengeId],
        'idempotency_key' => 'placement-terminal-cards',
        'placed_at' => now(),
    ]);

    $tracking = ItemTracking::for($item, 'ar');

    // Emptying the buttons while leaving "fix your sign-in details" above them is
    // the same defect one level down.
    expect($tracking['challenges'][0]['actions'])->toBe([])
        ->and($tracking['challenges'][0]['holdReason'])->toBeNull()
        ->and($tracking['challenges'][0]['holdMessage'])->toBeNull()
        ->and($tracking['challenges'][0]['holdTone'])->toBeNull()
        // The state itself stays: it is what happened, and the card still names it.
        ->and($tracking['challenges'][0]['state'])->toBe('sign_in_failed');
});

test('workStarted uses the exact finished check, so unfinished is not finished', function (
    string $status,
    bool $expected,
): void {
    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, ['observation' => ['status' => $status], 'completed_at' => null]);

    expect(ItemTracking::for($item, 'en')['workStarted'])->toBe($expected);
})->with([
    // The tracker tests the substring 'finish', which also matches this one. The
    // store pins it as not finished, and workStarted has to agree.
    'unfinished' => ['unfinished', false],
    'finished' => ['finished', true],
    'completed' => ['completed', true],
]);

test('workStarted is false on a challenge job until it completes, because a challenge observation carries none of the four signals', function (): void {
    $challengeId = '6f2c7cda-5555-6666-7777-888899990000';

    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Sbc);

    // A challenge observation is keyed by challenge id, so it has no top-level
    // status, accountCheck, economyState or simplifiedStatus to read. The tracker's
    // page-level predicate answers false on the same data; the per-card optimism is
    // a separate mechanism the client owns.
    $job = trackingJob($item, [
        'delivery_phase' => DeliveryPhase::Challenge,
        'observation' => [$challengeId => ['sbcStatus' => 'solvingChallenge']],
    ]);

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => [$challengeId],
        'idempotency_key' => 'placement-workstarted-challenge',
        'placed_at' => now(),
    ]);

    expect(ItemTracking::for($item, 'en')['workStarted'])->toBeFalse();

    $job->update(['completed_at' => CarbonImmutable::now()]);

    expect(ItemTracking::for($item->fresh(), 'en')['workStarted'])->toBeTrue();
});

test('a challenge wait is never labelled as a failure', function (string $sbcStatus): void {
    // The aggregate resolves these six to Processing with an Info tone. A card
    // chip reading "could not solve" beside that headline is the screen
    // contradicting itself, which is how this was found: by looking at it.
    $state = SupplierStateTranslator::challengeState($sbcStatus);

    expect($state)->not->toBe(ChallengeState::Failed)
        ->and($state->label('ar'))->not->toBe(ChallengeState::Failed->label('ar'))
        // The tracker's own labels for the connection cases name the proxy.
        ->and($state->label('ar'))->not->toContain('بروكسي')
        ->and($state->label('en'))->not->toContain('proxy');
})->with(SupplierStateTranslator::SBC_SYSTEM_INFO_STATUSES);

test('every tracker status has its own label in both languages, without naming plumbing', function (): void {
    $statuses = [
        'entered', 'waitingForOtherSolve', 'started', 'fetchSBCInfo', 'fetchChallengeInfo', 'solvingChallenge', 'finished',
        'sessionExpired', 'needEmailConfirm', 'LoginFailed495', 'LoginFailed401', 'LoginFailedDeviceBan', 'LoginError', 'LoginFailed', 'WrongUserPass', '2FADisabled', 'No2FA', 'WrongBA', 'loginLoop', 'loginFailed',
        'FailProxyConn', 'FailedProxyConnectionError', 'FailProxy',
        'failedNoClub', 'consoleLoggedIn', 'FailedPersonaSwitch', 'TMLocked',
        'setNotFound', 'foundationNotSolved', 'alreadyCompleted', 'challengeDataMissing', 'noSolutionFound', 'tooExpensive', 'clickFailed', 'submitFailed', 'squadCreateFailed',
        'playerBuyFailed', 'playerNotFound', 'playerNotMoved', 'clubQueryFailed', 'tooManyExchanges',
        'noFunds', 'OutOfCoins', 'tempban', 'TempbanCooldown', 'dailyReceiverLimit',
        'aborted', 'failed', 'FailUnassignedFound',
    ];

    $forbidden = ['بروكسي', 'proxy', '401', '495', 'fft', 'utt'];

    foreach ($statuses as $status) {
        expect(SbcStatusPresentation::has($status))->toBeTrue("{$status} is missing from the presentation table");

        foreach (['ar', 'en'] as $locale) {
            $label = trans("orders.challenge_statuses.{$status}", [], $locale);

            expect($label)->toBeString()->not->toBe('')->not->toBe("orders.challenge_statuses.{$status}");

            foreach ($forbidden as $word) {
                expect(mb_strtolower($label))->not->toContain($word);
            }
        }
    }
});

test('a challenge tone comes from the status, not from the hold message', function (string $sbcStatus, string $expectedTone): void {
    $tracking = challengeTracking([$sbcStatus]);

    expect($tracking['challenges'][0]['tone'])->toBe($expectedTone);
})->with([
    'finished is done' => ['finished', 'success'],
    'a solve in flight is working' => ['solvingChallenge', 'working'],
    'a cooldown waits' => ['tempban', 'waiting'],
    'the daily limit waits' => ['dailyReceiverLimit', 'waiting'],
    'no-hold too expensive is stopped' => ['tooExpensive', 'danger'],
    'no-hold click failure is stopped' => ['clickFailed', 'danger'],
    'no-hold failure is stopped' => ['failed', 'danger'],
]);

test('a failure with no hold reason still reads as stopped, not in-progress', function (string $sbcStatus): void {
    $tracking = challengeTracking([$sbcStatus]);

    expect($tracking['challenges'][0]['holdReason'])->toBeNull()
        ->and($tracking['challenges'][0]['tone'])->toBe('danger')
        ->and($tracking['challenges'][0]['state'])->toBe('failed');
})->with(['tooExpensive', 'clickFailed', 'failed']);

test('LoginFailed401 help does not blame the password; WrongUserPass help does', function (): void {
    $refused = challengeTracking(['LoginFailed401']);
    $refusedHelp = $refused['challenges'][0]['help'];

    expect($refusedHelp['title'])->not->toContain('password')
        ->and($refusedHelp['desc'])->not->toContain('password')
        ->and($refusedHelp['action'])->not->toContain('password');

    $wrong = challengeTracking(['WrongUserPass']);
    $wrongHelp = $wrong['challenges'][0]['help'];

    expect($wrongHelp['desc'])->toContain('password')
        ->and($wrongHelp['action'])->toContain('details');
});

test('TMLocked help does not tell the customer to retry', function (): void {
    $tracking = challengeTracking(['TMLocked']);
    $help = $tracking['challenges'][0]['help'];

    expect($help['action'])->not->toContain('retry')
        ->and($help['action'])->not->toContain('Try again')
        ->and($help['action'])->not->toContain('إعادة المحاولة');
});

test('a status absent from the presentation table still returns a label and a tone', function (): void {
    // noPriceFound is a real SBC status the tracker's display map does not name;
    // the presenter falls back to the coarse state instead of crashing.
    expect(SbcStatusPresentation::has('noPriceFound'))->toBeFalse();

    $tracking = challengeTracking(['noPriceFound']);

    expect($tracking['challenges'][0]['stateLabel'])->toBeString()->not->toBe('')
        ->and($tracking['challenges'][0]['tone'])->toBeString()->not->toBe('')
        ->and($tracking['challenges'][0]['help'])->toHaveKeys(['title', 'desc', 'action']);
});

test('opening the order page asks the supplier, and shows what it just said', function (): void {
    // The owner's decision: "opening the page is the refresh". There is no button
    // on the screen, so this is the only moment a customer can cause a read - and
    // a page that answers from storage alone makes reopening it pointless.
    config()->set('services.suppliers.fft.base_url', 'https://fft.example.test');
    config()->set('services.suppliers.fft.api_user', 'store-fft');
    config()->set('services.suppliers.fft.api_key', 'fft-key');
    Http::preventStrayRequests();

    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'observed_state' => 'entered',
        'observation' => ['status' => 'entered'],
        'observed_at' => CarbonImmutable::parse('2026-09-12 10:00:00'),
        'coins_delivered' => 0,
        'coins_ordered' => 1000,
    ]);

    // FFT reports in thousands, which is why 400 here is 400,000 on the screen.
    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response([
            'status' => 'transfersInProgress',
            'economyState' => 'transfersInProgress',
            'amount' => 400,
            'amountOrdered' => 1000,
        ]),
    ]);

    $this->actingAs($owner)
        ->get('/my-account/orders/'.$order->order_number)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.items.0.tracking.progress.coinsDelivered', 400_000));

    Http::assertSentCount(1);
});

test('a supplier that is not configured leaves the order page standing', function (): void {
    // A missing key is a deployment fault. Now that opening the page performs the
    // read, an uncaught one would turn every customer's order into an error page.
    config()->set('services.suppliers.fft.base_url', 'https://fft.example.test');
    config()->set('services.suppliers.fft.api_user', null);
    config()->set('services.suppliers.fft.api_key', null);
    Http::preventStrayRequests();

    $owner = User::factory()->create();
    $order = trackingOrder($owner);
    $item = trackingItem($order, ServiceType::Coins);

    trackingJob($item, [
        'delivery_phase' => DeliveryPhase::Coins,
        'observed_state' => 'transfersInProgress',
        'observation' => ['status' => 'transfersInProgress'],
        'observed_at' => CarbonImmutable::parse('2026-09-12 10:00:00'),
        'coins_delivered' => 250,
        'coins_ordered' => 1000,
    ]);

    $this->actingAs($owner)
        ->get('/my-account/orders/'.$order->order_number)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // The stored reading survives, with its age, which is what the screen
            // shows when it cannot get a fresher one.
            ->where('order.items.0.tracking.progress.coinsDelivered', 250));
});
