<?php

use App\Actions\Fulfillment\EnqueueOrderPlacement;
use App\Models\IntegrationEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function requeueEvent(array $overrides = []): IntegrationEvent
{
    // The publisher composes the request from the order itself, so the event
    // needs a real order behind it - one Coins item with an EA account, and a
    // pricing run to budget against.
    static $sequence = 1000;
    $sequence++;

    $order = placeableOrder('AUT-REQUEUE-'.$sequence);
    $event = app(EnqueueOrderPlacement::class)->execute($order);

    $event->forceFill($overrides)->save();

    return $event->fresh();
}

beforeEach(function (): void {
    config()->set('services.n8n.order_paid_url', 'https://n8n.example.test/webhook/arab-ut-order-paid');
    config()->set('services.n8n.order_paid_key', 'checkout-publisher');
    config()->set('services.n8n.order_paid_secret', str_repeat('s', 48));
    Http::preventStrayRequests();
});

test('requeueing a failed event makes it eligible for delivery again', function (): void {
    $event = requeueEvent([
        'status' => 'failed',
        'attempts' => 10,
        'last_error' => 'max_attempts_exceeded',
        'available_at' => now()->subHours(3),
    ]);

    $this->artisan('orders:requeue-paid-event', ['event_id' => $event->event_id])
        ->expectsOutputToContain('requeued')
        ->assertSuccessful();

    expect($event->fresh()->status)->toBe('pending')
        ->and($event->fresh()->attempts)->toBe(0)
        ->and($event->fresh()->last_error)->toBeNull()
        ->and($event->fresh()->processed_at)->toBeNull();

    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    $this->artisan('orders:publish-paid-events')->assertSuccessful();

    expect($event->fresh()->status)->toBe('processed')
        ->and($event->fresh()->attempts)->toBe(1);
});

test('requeueing refuses an event that is not failed', function (): void {
    $pending = requeueEvent();
    $processed = requeueEvent([
        'status' => 'processed',
        'attempts' => 1,
        'processed_at' => now(),
    ]);

    $this->artisan('orders:requeue-paid-event', ['event_id' => $pending->event_id])->assertFailed();
    $this->artisan('orders:requeue-paid-event', ['event_id' => $processed->event_id])->assertFailed();

    expect($pending->fresh()->status)->toBe('pending')
        ->and($processed->fresh()->status)->toBe('processed')
        ->and($processed->fresh()->attempts)->toBe(1);
});

test('requeueing an unknown event id fails without touching anything', function (): void {
    $event = requeueEvent(['status' => 'failed', 'attempts' => 10]);

    $this->artisan('orders:requeue-paid-event', ['event_id' => (string) Str::ulid()])->assertFailed();

    expect($event->fresh()->status)->toBe('failed');
});
