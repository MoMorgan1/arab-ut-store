<?php

use App\Models\IntegrationEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

function publisherEvent(array $overrides = []): IntegrationEvent
{
    $suffix = (string) Str::ulid();

    return IntegrationEvent::create(array_merge([
        'event_id' => $suffix,
        'event_type' => 'order.paid',
        'aggregate_type' => 'order',
        'aggregate_id' => (string) Str::ulid(),
        'schema_version' => 1,
        'payload' => [
            'order_public_id' => (string) Str::ulid(),
            'order_number' => 'AUT-PUB-1001',
            'locale' => 'ar',
            'currency' => 'SAR',
            'total_halalah' => 1250,
            'item_count' => 1,
        ],
        'status' => 'pending',
        'idempotency_key' => 'order-paid:pub:'.$suffix,
        'attempts' => 0,
        'available_at' => now(),
    ], $overrides));
}

beforeEach(function (): void {
    config()->set('services.n8n.order_paid_url', 'https://n8n.example.test/webhook/arab-ut-order-paid');
    config()->set('services.n8n.order_paid_key', 'checkout-publisher');
    config()->set('services.n8n.order_paid_secret', str_repeat('s', 48));
    Http::preventStrayRequests();
});

test('an event at the attempt ceiling is retired as failed instead of staying pending forever', function (): void {
    $event = publisherEvent(['attempts' => 10, 'available_at' => now()->subMinute()]);

    $logged = [];
    Log::listen(function ($log) use (&$logged): void {
        $logged[] = $log;
    });

    $this->artisan('orders:publish-paid-events')->assertSuccessful();

    $event->refresh();
    expect($event->status)->toBe('failed')
        ->and($event->attempts)->toBe(10)
        ->and($event->last_error)->toBe('max_attempts_exceeded')
        ->and($event->processed_at)->toBeNull();

    $retirement = collect($logged)->first(
        fn ($log): bool => $log->level === 'error' && str_contains((string) $log->message, 'retired'),
    );
    expect($retirement)->not->toBeNull()
        ->and($retirement->context['event_id'])->toBe($event->event_id)
        ->and($retirement->context['aggregate_id'])->toBe($event->aggregate_id)
        ->and($retirement->context['order_number'])->toBe('AUT-PUB-1001')
        ->and($retirement->context['attempts'])->toBe(10);
});

test('the attempt ceiling follows configuration', function (): void {
    config()->set('services.n8n.order_paid_max_attempts', 3);
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    $exhausted = publisherEvent(['attempts' => 3, 'available_at' => now()->subMinute()]);
    $spared = publisherEvent(['attempts' => 2, 'available_at' => now()->subMinute()]);

    $this->artisan('orders:publish-paid-events')->assertSuccessful();

    expect($exhausted->fresh()->status)->toBe('failed')
        ->and($spared->fresh()->status)->toBe('processed');
});

test('a failed event is never picked up again by the publisher', function (): void {
    $event = publisherEvent([
        'status' => 'failed',
        'attempts' => 10,
        'last_error' => 'max_attempts_exceeded',
        'available_at' => now()->subHours(2),
    ]);

    $this->artisan('orders:publish-paid-events')->assertSuccessful();
    $this->artisan('orders:publish-paid-events')->assertSuccessful();

    $event->refresh();
    expect($event->status)->toBe('failed')
        ->and($event->attempts)->toBe(10);
});

test('events below the ceiling are still delivered', function (): void {
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    $event = publisherEvent(['attempts' => 9, 'available_at' => now()->subMinute()]);

    $this->artisan('orders:publish-paid-events')->assertSuccessful();

    $event->refresh();
    expect($event->status)->toBe('processed')
        ->and($event->attempts)->toBe(10)
        ->and($event->last_error)->toBeNull();
});
