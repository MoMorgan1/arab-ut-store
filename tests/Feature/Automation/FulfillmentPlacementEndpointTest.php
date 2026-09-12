<?php

use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\Order;
use App\Models\OrderItem;
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
    $payload = placementPayload($item, ['delivery_phase' => DeliveryPhase::Challenge->value]);

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
});

it('records a plain coins placement without a delivery phase', function () {
    $item = paidOrderItem(ServiceType::Coins);

    signedFulfillmentPlacement(placementPayload($item))
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true);

    expect(FulfillmentJob::sole()->delivery_phase)->toBeNull();
});

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
        ->and($job->status)->toBe(FulfillmentStatus::Completed)
        ->and($job->completed_at)->not->toBeNull()
        ->and($job->attempt_count)->toBe(3)
        ->and($job->last_error)->toBe('kept')
        ->and($job->last_error_code)->toBe('supplier_5xx')
        ->and($job->next_poll_at->equalTo($polledAt))->toBeTrue();
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

it('refuses a second placement for an item that already holds a different one', function () {
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
        ->and($job->supplier_order_id)->toBe($payload['supplier_order_id']);
});

it('does not reveal anything about an unknown order item id', function () {
    signedFulfillmentPlacement([
        'order_item_public_id' => (string) Str::ulid(),
        'supplier' => Supplier::Fft->value,
        'supplier_order_id' => 'FFT-UNKNOWN',
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
]);

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
