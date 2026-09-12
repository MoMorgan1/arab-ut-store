<?php

use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const FULFILLMENT_TEST_SECRET = 'ffffffffffffffffffffffffffffffffffffffffffffffff';

function signedFulfillmentPlacement(
    array $payload,
    ?string $signature = null,
    bool $configureCredentials = true,
    ?string $timestamp = null,
    string $secret = FULFILLMENT_TEST_SECRET,
    string $key = 'fulfillment-test-key',
    string $configuredKey = 'fulfillment-test-key',
) {
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $timestamp ??= (string) now()->timestamp;
    $event = (string) Str::ulid();

    if ($configureCredentials) {
        config()->set('services.n8n.fulfillment_key', $configuredKey);
        config()->set('services.n8n.fulfillment_secret', $secret);
    }

    return test()->call(
        'POST',
        '/api/automation/v1/fulfillment/placements',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ARABUT_KEY' => $key,
            'HTTP_X_ARABUT_TIMESTAMP' => $timestamp,
            'HTTP_X_ARABUT_EVENT' => $event,
            'HTTP_X_ARABUT_SIGNATURE' => $signature ?? hash_hmac(
                'sha256',
                $timestamp."\n".$event."\n".$body,
                $secret,
            ),
        ],
        content: $body,
    );
}

/** @return array<string, mixed> */
function placementPayload(OrderItem $item, array $changes = []): array
{
    return array_replace([
        'order_item_public_id' => (string) $item->public_id,
        'supplier' => Supplier::Fft->value,
        'supplier_order_id' => 'FFT-'.Str::ulid(),
        'delivery_phase' => DeliveryPhase::Coins->value,
    ], $changes);
}

function paidOrderItem(ServiceType $service = ServiceType::Coins): OrderItem
{
    $order = Order::factory()->create(['paid_at' => now()]);

    return OrderItem::factory()->create([
        'order_id' => $order->id,
        'service_type' => $service,
    ]);
}

it('records a signed placement and starts polling', function () {
    $item = paidOrderItem(ServiceType::Sbc);
    $payload = placementPayload($item, [
        'delivery_phase' => DeliveryPhase::Challenge->value,
        'challenge_ids' => ['c6d05f3b-63a1-4328-98e3-b09e4a305fbb'],
    ]);

    $response = signedFulfillmentPlacement($payload)
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true)
        ->assertJsonPath('data.order_item_public_id', (string) $item->public_id)
        ->assertJsonPath('data.supplier', Supplier::Fft->value)
        ->assertJsonPath('data.supplier_order_id', $payload['supplier_order_id']);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $job = FulfillmentJob::sole();

    expect($response->json('data.job_public_id'))->toBe((string) $job->public_id)
        ->and($job->order_item_id)->toBe($item->id)
        ->and($job->status)->toBe(FulfillmentStatus::InProgress)
        ->and($job->supplier)->toBe(Supplier::Fft)
        ->and($job->supplier_order_id)->toBe($payload['supplier_order_id'])
        ->and($job->delivery_phase)->toBe(DeliveryPhase::Challenge)
        ->and($job->attempt_count)->toBe(0)
        ->and($job->next_poll_at)->not->toBeNull()
        ->and($job->idempotency_key)->toBe('fulfillment-placement:'.$item->public_id);

    $placement = FulfillmentPlacement::sole();

    expect($placement->fulfillment_job_id)->toBe($job->id)
        ->and($placement->delivery_phase)->toBe(DeliveryPhase::Challenge)
        ->and($placement->supplier)->toBe(Supplier::Fft)
        ->and($placement->supplier_order_id)->toBe($payload['supplier_order_id'])
        ->and($placement->challengeIds())->toBe(['c6d05f3b-63a1-4328-98e3-b09e4a305fbb'])
        ->and($placement->idempotency_key)->toBe('fulfillment-placement:'.$item->public_id.':challenge')
        ->and($placement->placed_at)->not->toBeNull();
});

it('mirrors a plain coins placement on the job and records its phase', function () {
    $item = paidOrderItem(ServiceType::Coins);
    $payload = placementPayload($item);

    signedFulfillmentPlacement($payload)
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true);

    $job = FulfillmentJob::sole();

    expect($job->delivery_phase)->toBe(DeliveryPhase::Coins)
        ->and($job->supplier)->toBe(Supplier::Fft)
        ->and($job->supplier_order_id)->toBe($payload['supplier_order_id']);

    $placement = FulfillmentPlacement::sole();

    expect($placement->fulfillment_job_id)->toBe($job->id)
        ->and($placement->delivery_phase)->toBe(DeliveryPhase::Coins)
        ->and($placement->supplier_order_id)->toBe($payload['supplier_order_id'])
        ->and($placement->idempotency_key)->toBe('fulfillment-placement:'.$item->public_id.':coins');
});

it('completes a half-written job mirror instead of refusing forever', function (array $mirror) {
    $item = paidOrderItem();
    $reference = 'FFT-HALF-8837410';

    $job = FulfillmentJob::factory()->create(array_replace([
        'order_item_id' => $item->id,
        'delivery_phase' => null,
    ], $mirror));

    signedFulfillmentPlacement(placementPayload($item, ['supplier_order_id' => $reference]))
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true);

    $job->refresh();

    expect($job->supplier)->toBe(Supplier::Fft)
        ->and($job->supplier_order_id)->toBe($reference)
        ->and($job->delivery_phase)->toBe(DeliveryPhase::Coins)
        ->and($job->placements()->count())->toBe(1)
        ->and(FulfillmentPlacement::sole()->supplier_order_id)->toBe($reference);
})->with([
    'supplier without a reference' => [['supplier' => Supplier::Fft]],
    'reference without a supplier' => [['supplier_order_id' => 'FFT-HALF-ORPHAN']],
]);

it('treats an identical retry as a no-op that grants nothing', function () {
    $item = paidOrderItem();
    $payload = placementPayload($item);

    signedFulfillmentPlacement($payload)->assertOk();

    $job = FulfillmentJob::sole();
    $polledAt = $job->next_poll_at;
    $job->forceFill([
        'status' => FulfillmentStatus::Completed,
        'completed_at' => now(),
        'attempt_count' => 3,
        'last_error_code' => 'supplier_5xx',
        'last_error' => 'kept',
    ])->save();

    signedFulfillmentPlacement($payload)
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true);

    $job->refresh();

    expect(FulfillmentJob::count())->toBe(1)
        ->and(FulfillmentPlacement::count())->toBe(1)
        ->and($job->status)->toBe(FulfillmentStatus::Completed)
        ->and($job->completed_at)->not->toBeNull()
        ->and($job->attempt_count)->toBe(3)
        ->and($job->last_error)->toBe('kept')
        ->and($job->last_error_code)->toBe('supplier_5xx')
        ->and($job->next_poll_at->equalTo($polledAt))->toBeTrue();
});

it('records both phases of a challenge against one job without moving the mirror', function () {
    $item = paidOrderItem(ServiceType::Sbc);
    $coinsReference = 'FFT-COINS-8837410';

    $coins = signedFulfillmentPlacement(placementPayload($item, [
        'supplier_order_id' => $coinsReference,
    ]))->assertOk();

    $challenge = signedFulfillmentPlacement(placementPayload($item, [
        'supplier_order_id' => 'FFT-CHL-8837410',
        'delivery_phase' => DeliveryPhase::Challenge->value,
        'challenge_ids' => ['c6d05f3b-63a1-4328-98e3-b09e4a305fbb'],
    ]))->assertOk();

    expect($challenge->json('data.job_public_id'))->toBe($coins->json('data.job_public_id'));

    $job = FulfillmentJob::sole();

    expect(FulfillmentJob::count())->toBe(1)
        ->and($job->supplier)->toBe(Supplier::Fft)
        ->and($job->supplier_order_id)->toBe($coinsReference)
        ->and($job->delivery_phase)->toBe(DeliveryPhase::Coins)
        ->and($job->placements()->count())->toBe(2);

    $placements = FulfillmentPlacement::query()
        ->get()
        ->keyBy(fn (FulfillmentPlacement $placement): string => $placement->delivery_phase->value);

    expect($placements)->toHaveCount(2)
        ->and($placements['coins']->supplier_order_id)->toBe($coinsReference)
        ->and($placements['coins']->challengeIds())->toBe([])
        ->and($placements['coins']->idempotency_key)->toBe('fulfillment-placement:'.$item->public_id.':coins')
        ->and($placements['challenge']->supplier_order_id)->toBe('FFT-CHL-8837410')
        ->and($placements['challenge']->challengeIds())->toBe(['c6d05f3b-63a1-4328-98e3-b09e4a305fbb'])
        ->and($placements['challenge']->idempotency_key)->toBe('fulfillment-placement:'.$item->public_id.':challenge');
});

it('treats a retry of one phase as a no-op after the other phase landed', function () {
    $item = paidOrderItem(ServiceType::Sbc);
    $coins = placementPayload($item, ['supplier_order_id' => 'FFT-COINS-8837410']);

    signedFulfillmentPlacement($coins)->assertOk();

    signedFulfillmentPlacement(placementPayload($item, [
        'supplier_order_id' => 'FFT-CHL-8837410',
        'delivery_phase' => DeliveryPhase::Challenge->value,
        'challenge_ids' => ['c6d05f3b-63a1-4328-98e3-b09e4a305fbb'],
    ]))->assertOk();

    FulfillmentJob::sole()->forceFill(['status' => FulfillmentStatus::Completed])->save();

    signedFulfillmentPlacement($coins)
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true);

    expect(FulfillmentPlacement::count())->toBe(2)
        ->and(FulfillmentJob::sole()->status)->toBe(FulfillmentStatus::Completed);
});

it('refuses a reference that is already bound to another item', function () {
    $first = paidOrderItem();
    $second = paidOrderItem();
    $payload = placementPayload($first);

    signedFulfillmentPlacement($payload)->assertOk();

    signedFulfillmentPlacement(placementPayload($second, [
        'supplier_order_id' => $payload['supplier_order_id'],
    ]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'supplier_reference_conflict');

    expect(FulfillmentJob::query()->where('order_item_id', $second->id)->exists())->toBeFalse()
        ->and(FulfillmentJob::sole()->order_item_id)->toBe($first->id);
});

it('refuses a second placement for a phase that already holds a different one', function () {
    $item = paidOrderItem();
    $payload = placementPayload($item);

    signedFulfillmentPlacement($payload)->assertOk();

    signedFulfillmentPlacement(placementPayload($item, ['supplier_order_id' => 'FFT-OTHER']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'item_placement_conflict');

    signedFulfillmentPlacement(placementPayload($item, [
        'supplier' => Supplier::Utt->value,
        'supplier_order_id' => 'UTT-OTHER',
    ]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'item_placement_conflict');

    $job = FulfillmentJob::sole();

    expect($job->supplier)->toBe(Supplier::Fft)
        ->and($job->supplier_order_id)->toBe($payload['supplier_order_id'])
        ->and(FulfillmentPlacement::count())->toBe(1);
});

it('refuses a reference already recorded by the other phase of the same item', function () {
    $item = paidOrderItem(ServiceType::Sbc);
    $reference = 'FFT-SHARED-8837410';

    signedFulfillmentPlacement(placementPayload($item, [
        'supplier_order_id' => $reference,
    ]))->assertOk();

    signedFulfillmentPlacement(placementPayload($item, [
        'supplier_order_id' => $reference,
        'delivery_phase' => DeliveryPhase::Challenge->value,
        'challenge_ids' => ['c6d05f3b-63a1-4328-98e3-b09e4a305fbb'],
    ]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'supplier_reference_conflict');

    expect(FulfillmentPlacement::count())->toBe(1);
});

it('does not reveal anything about an unknown order item id', function () {
    signedFulfillmentPlacement([
        'order_item_public_id' => (string) Str::ulid(),
        'supplier' => Supplier::Fft->value,
        'supplier_order_id' => 'FFT-UNKNOWN',
        'delivery_phase' => DeliveryPhase::Coins->value,
    ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'order_item_not_found')
        ->assertJsonPath('error.message', 'The referenced order item does not exist.');

    expect(FulfillmentJob::count())->toBe(0);
});

it('keeps Objectives out although the enum does not call it manual', function () {
    // ServiceType::isManual() lists only Rivals and FUT Champions, so a
    // ! isManual() rule would treat Objectives as supplier-delivered. The
    // automated set is deliberately stated positively in the action instead.
    signedFulfillmentPlacement(placementPayload(paidOrderItem(ServiceType::Objectives)))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'service_not_automated');

    expect(FulfillmentJob::count())->toBe(0);
});

it('refuses services that no supplier delivers', function (ServiceType $service) {
    $item = paidOrderItem($service);

    signedFulfillmentPlacement(placementPayload($item))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'service_not_automated');

    expect(FulfillmentJob::count())->toBe(0);
})->with([
    'rivals' => [ServiceType::Rivals],
    'fut champions' => [ServiceType::FutChampions],
]);

it('refuses a challenge phase on an item with no challenge to solve', function () {
    signedFulfillmentPlacement(placementPayload(paidOrderItem(ServiceType::Coins), [
        'supplier_order_id' => 'FFT-CHL-8837410',
        'delivery_phase' => DeliveryPhase::Challenge->value,
    ]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'service_has_no_challenge');

    expect(FulfillmentJob::count())->toBe(0)
        ->and(FulfillmentPlacement::count())->toBe(0);
});

it('refuses a supplier that does not solve challenges', function () {
    signedFulfillmentPlacement(placementPayload(paidOrderItem(ServiceType::Sbc), [
        'supplier' => Supplier::Utt->value,
        'supplier_order_id' => 'UTT-CHL-8837410',
        'delivery_phase' => DeliveryPhase::Challenge->value,
    ]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'supplier_cannot_solve_challenges');

    expect(FulfillmentJob::count())->toBe(0)
        ->and(FulfillmentPlacement::count())->toBe(0);
});

it('refuses an order that has not been paid', function () {
    $order = Order::factory()->create();
    $item = OrderItem::factory()->create([
        'order_id' => $order->id,
        'service_type' => ServiceType::Coins,
    ]);

    signedFulfillmentPlacement(placementPayload($item))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'order_item_unpaid');

    expect(FulfillmentJob::count())->toBe(0);
});

it('rejects a bad signature without touching placement state', function () {
    $item = paidOrderItem();

    $response = signedFulfillmentPlacement(placementPayload($item), str_repeat('0', 64))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_signature');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(FulfillmentJob::count())->toBe(0);
});

it('treats a secret shorter than 32 characters as unconfigured', function () {
    $item = paidOrderItem();

    signedFulfillmentPlacement(placementPayload($item), secret: str_repeat('s', 31))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_signature');

    expect(FulfillmentJob::count())->toBe(0);
});

it('rejects a correctly signed request outside the freshness window', function () {
    $item = paidOrderItem();

    signedFulfillmentPlacement(
        placementPayload($item),
        timestamp: (string) now()->subMinutes(6)->timestamp,
    )
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'stale_placement');

    expect(FulfillmentJob::count())->toBe(0);
});

it('rejects an invalid body before any placement work', function (array $overrides, string $field) {
    $payload = array_replace([
        'order_item_public_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'supplier' => Supplier::Fft->value,
        'supplier_order_id' => 'FFT-VALIDATION',
        'delivery_phase' => DeliveryPhase::Coins->value,
    ], $overrides);

    signedFulfillmentPlacement($payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(FulfillmentJob::count())->toBe(0);
})->with([
    'unknown supplier' => [['supplier' => 'salla'], 'supplier'],
    'missing reference' => [['supplier_order_id' => null], 'supplier_order_id'],
    'malformed item id' => [['order_item_public_id' => 'not-a-ulid'], 'order_item_public_id'],
    'unknown delivery phase' => [['delivery_phase' => 'both'], 'delivery_phase'],
    'missing delivery phase' => [['delivery_phase' => null], 'delivery_phase'],
]);

it('enforces one placement per phase and one item per reference in the schema', function () {
    $job = FulfillmentJob::factory()->create();

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'FFT-SCHEMA-1',
    ]);

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'FFT-SCHEMA-2',
    ]);

    expect(fn () => FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'FFT-SCHEMA-3',
    ]))->toThrow(UniqueConstraintViolationException::class);

    $otherJob = FulfillmentJob::factory()->create();

    expect(fn () => FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $otherJob->id,
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'FFT-SCHEMA-2',
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(FulfillmentPlacement::count())->toBe(2);
});

it('throttles a key that hammers the endpoint', function () {
    $item = paidOrderItem();
    $payload = placementPayload($item);

    for ($attempt = 0; $attempt < 10; $attempt++) {
        signedFulfillmentPlacement($payload)->assertOk();
    }

    signedFulfillmentPlacement($payload)->assertStatus(429);
});

it('authenticates before charging the trusted credential rate-limit bucket', function () {
    $item = paidOrderItem();
    $payload = placementPayload($item);

    foreach (range(1, 10) as $attempt) {
        signedFulfillmentPlacement($payload, signature: str_repeat((string) ($attempt % 10), 64))
            ->assertUnauthorized();
    }

    for ($attempt = 0; $attempt < 10; $attempt++) {
        signedFulfillmentPlacement($payload)->assertOk();
    }

    $limited = signedFulfillmentPlacement($payload)
        ->assertStatus(429);

    $limited->assertJsonPath('error.code', 'fulfillment_rate_limited');

    $invalidAfterLimit = signedFulfillmentPlacement($payload, signature: str_repeat('f', 64))
        ->assertUnauthorized();

    $rotatedInvalidAfterLimit = signedFulfillmentPlacement(
        $payload,
        signature: str_repeat('e', 64),
        key: 'rotated-invalid-key',
    )->assertUnauthorized();

    expect($limited->headers->get('Cache-Control'))->toContain('no-store')
        ->and($invalidAfterLimit->headers->get('Cache-Control'))->toContain('no-store')
        ->and($rotatedInvalidAfterLimit->headers->get('Cache-Control'))->toContain('no-store');
});

it('stores challenge ids normalised and returns them through the model accessor', function () {
    $item = paidOrderItem(ServiceType::Sbc);
    $rawIds = [
        'SBC-C6D05F3B-63A1-4328-98E3-B09E4A305FBB',
        '9C8B7A6D-1234-4567-890A-BCDEF0123456',
        'c6d05f3b-63a1-4328-98e3-b09e4a305fbb',
    ];

    signedFulfillmentPlacement(placementPayload($item, [
        'delivery_phase' => DeliveryPhase::Challenge->value,
        'challenge_ids' => $rawIds,
    ]))
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true);

    $placement = FulfillmentPlacement::sole();

    expect($placement->challengeIds())->toBe([
        'c6d05f3b-63a1-4328-98e3-b09e4a305fbb',
        '9c8b7a6d-1234-4567-890a-bcdef0123456',
    ])
        ->and($placement->challengeIds())->toBe([
            'c6d05f3b-63a1-4328-98e3-b09e4a305fbb',
            '9c8b7a6d-1234-4567-890a-bcdef0123456',
        ])
        ->and($placement->supplier_challenge_ids)->toBe([
            'c6d05f3b-63a1-4328-98e3-b09e4a305fbb',
            '9c8b7a6d-1234-4567-890a-bcdef0123456',
        ]);
});

it('accepts challenge ids as a comma-separated string and stores them identically', function () {
    $item = paidOrderItem(ServiceType::Sbc);
    $stringIds = 'SBC-C6D05F3B-63A1-4328-98E3-B09E4A305FBB, 9C8B7A6D-1234-4567-890A-BCDEF0123456';

    signedFulfillmentPlacement(placementPayload($item, [
        'delivery_phase' => DeliveryPhase::Challenge->value,
        'challenge_ids' => $stringIds,
    ]))
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true);

    $placement = FulfillmentPlacement::sole();

    expect($placement->challengeIds())->toBe([
        'c6d05f3b-63a1-4328-98e3-b09e4a305fbb',
        '9c8b7a6d-1234-4567-890a-bcdef0123456',
    ]);
});

it('refuses a challenge placement without challenge ids', function () {
    $item = paidOrderItem(ServiceType::Sbc);

    signedFulfillmentPlacement(placementPayload($item, [
        'delivery_phase' => DeliveryPhase::Challenge->value,
    ]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'challenge_ids_required');

    expect(FulfillmentPlacement::count())->toBe(0)
        ->and(FulfillmentJob::count())->toBe(0);
});

it('refuses a coins placement carrying challenge ids', function () {
    $item = paidOrderItem(ServiceType::Coins);

    signedFulfillmentPlacement(placementPayload($item, [
        'delivery_phase' => DeliveryPhase::Coins->value,
        'challenge_ids' => ['c6d05f3b-63a1-4328-98e3-b09e4a305fbb'],
    ]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'challenge_ids_not_permitted');

    expect(FulfillmentPlacement::count())->toBe(0)
        ->and(FulfillmentJob::count())->toBe(0);
});

it('rejects a list containing a malformed id and stores nothing', function () {
    $item = paidOrderItem(ServiceType::Sbc);

    signedFulfillmentPlacement(placementPayload($item, [
        'delivery_phase' => DeliveryPhase::Challenge->value,
        'challenge_ids' => [
            'c6d05f3b-63a1-4328-98e3-b09e4a305fbb',
            'not-a-valid-uuid',
        ],
    ]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_challenge_ids')
        ->assertJsonPath('error.message', '1 challenge id is invalid.');

    expect(FulfillmentPlacement::count())->toBe(0)
        ->and(FulfillmentJob::count())->toBe(0);
});

it('reports the count of multiple malformed ids without naming them back', function () {
    $item = paidOrderItem(ServiceType::Sbc);

    signedFulfillmentPlacement(placementPayload($item, [
        'delivery_phase' => DeliveryPhase::Challenge->value,
        'challenge_ids' => [
            'bad-id-1',
            'bad-id-2',
            'c6d05f3b-63a1-4328-98e3-b09e4a305fbb',
        ],
    ]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_challenge_ids')
        ->assertJsonPath('error.message', '2 challenge ids are invalid.');

    expect(FulfillmentPlacement::count())->toBe(0)
        ->and(FulfillmentJob::count())->toBe(0);
});

it('treats identical retry with same ids as no-op and conflicts on different ids', function () {
    $item = paidOrderItem(ServiceType::Sbc);
    $initialIds = ['c6d05f3b-63a1-4328-98e3-b09e4a305fbb'];
    $differentIds = ['9c8b7a6d-1234-4567-890a-bcdef0123456'];
    $reference = 'FFT-CHL-8837410';

    $payload = placementPayload($item, [
        'supplier_order_id' => $reference,
        'delivery_phase' => DeliveryPhase::Challenge->value,
        'challenge_ids' => $initialIds,
    ]);

    signedFulfillmentPlacement($payload)->assertOk();

    signedFulfillmentPlacement($payload)
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true);

    expect(FulfillmentPlacement::count())->toBe(1);

    signedFulfillmentPlacement(placementPayload($item, [
        'supplier_order_id' => $reference,
        'delivery_phase' => DeliveryPhase::Challenge->value,
        'challenge_ids' => $differentIds,
    ]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'item_placement_conflict');

    expect(FulfillmentPlacement::count())->toBe(1)
        ->and(FulfillmentPlacement::sole()->challengeIds())->toBe($initialIds);
});

it('treats retry with ids differing only in case or SBC prefix as a no-op', function () {
    $item = paidOrderItem(ServiceType::Sbc);
    $canonicalId = 'c6d05f3b-63a1-4328-98e3-b09e4a305fbb';
    $reference = 'FFT-CHL-8837410';

    signedFulfillmentPlacement(placementPayload($item, [
        'supplier_order_id' => $reference,
        'delivery_phase' => DeliveryPhase::Challenge->value,
        'challenge_ids' => [$canonicalId],
    ]))->assertOk();

    signedFulfillmentPlacement(placementPayload($item, [
        'supplier_order_id' => $reference,
        'delivery_phase' => DeliveryPhase::Challenge->value,
        'challenge_ids' => ['SBC-'.strtoupper($canonicalId)],
    ]))
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true);

    expect(FulfillmentPlacement::count())->toBe(1)
        ->and(FulfillmentPlacement::sole()->challengeIds())->toBe([$canonicalId]);
});
