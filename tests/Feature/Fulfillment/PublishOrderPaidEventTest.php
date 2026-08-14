<?php

use App\Actions\Fulfillment\PublishOrderPaidEvent;
use App\Models\IntegrationEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function pendingOrderPaidEvent(): IntegrationEvent
{
    return IntegrationEvent::create([
        'event_id' => (string) Str::ulid(),
        'event_type' => 'order.paid',
        'aggregate_type' => 'order',
        'aggregate_id' => (string) Str::ulid(),
        'schema_version' => 1,
        'payload' => [
            'order_public_id' => (string) Str::ulid(),
            'order_number' => 'AUT-PAID-1001',
            'locale' => 'ar',
            'currency' => 'SAR',
            'total_halalah' => 1250,
            'item_count' => 1,
        ],
        'status' => 'pending',
        'idempotency_key' => 'order-paid:test-1001',
        'attempts' => 0,
        'available_at' => now(),
    ]);
}

beforeEach(function (): void {
    config()->set('services.n8n.order_paid_url', 'https://n8n.example.test/webhook/arab-ut-order-paid');
    config()->set('services.n8n.order_paid_key', 'checkout-publisher');
    config()->set('services.n8n.order_paid_secret', str_repeat('s', 48));
    Http::preventStrayRequests();
});

test('a pending paid order event is delivered once with a signed secret free body', function () {
    $event = pendingOrderPaidEvent();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    expect(app(PublishOrderPaidEvent::class)->execute($event))->toBeTrue();

    $event->refresh();
    expect($event->status)->toBe('processed')
        ->and($event->attempts)->toBe(1)
        ->and($event->processed_at)->not->toBeNull()
        ->and($event->last_error)->toBeNull();

    Http::assertSent(function (Request $request) use ($event): bool {
        $raw = $request->body();
        $timestamp = $request->header('X-ArabUT-Timestamp')[0] ?? '';
        $expected = hash_hmac('sha256', $timestamp."\n".$event->event_id."\n".$raw, str_repeat('s', 48));

        return $request->url() === 'https://n8n.example.test/webhook/arab-ut-order-paid'
            && $request->method() === 'POST'
            && $request->hasHeader('X-ArabUT-Key', 'checkout-publisher')
            && $request->hasHeader('X-ArabUT-Event', $event->event_id)
            && $request->hasHeader('X-ArabUT-Signature', $expected)
            && str_contains($raw, 'AUT-PAID-1001')
            && ! str_contains($raw, 'ea_password')
            && ! str_contains($raw, 'backup_codes');
    });

    expect(app(PublishOrderPaidEvent::class)->execute($event->fresh()))->toBeTrue();
    Http::assertSentCount(1);
});

test('publisher failures remain retryable and never persist provider response details', function () {
    $event = pendingOrderPaidEvent();
    Http::fake(['https://n8n.example.test/*' => Http::response(['secret' => 'never-store-this'], 503)]);

    expect(app(PublishOrderPaidEvent::class)->execute($event))->toBeFalse();

    $event->refresh();
    expect($event->status)->toBe('pending')
        ->and($event->attempts)->toBe(1)
        ->and($event->available_at->isAfter(now()))->toBeTrue()
        ->and($event->last_error)->toBe('delivery_failed')
        ->and($event->last_error)->not->toContain('never-store-this');
});

test('missing or unsafe publisher configuration fails closed before the network', function (string $field, mixed $value) {
    config()->set("services.n8n.{$field}", $value);
    $event = pendingOrderPaidEvent();
    Http::fake();

    expect(app(PublishOrderPaidEvent::class)->execute($event))->toBeFalse();
    Http::assertNothingSent();
})->with([
    'missing URL' => ['order_paid_url', null],
    'insecure URL' => ['order_paid_url', 'http://n8n.example.test/webhook/order'],
    'missing key' => ['order_paid_key', null],
    'short secret' => ['order_paid_secret', 'short'],
]);
