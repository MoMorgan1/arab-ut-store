<?php

use App\Actions\Fulfillment\EnqueueOrderPlacement;
use App\Models\IntegrationEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function publisherEvent(array $overrides = []): IntegrationEvent
{
    // The publisher composes the request from the order itself, so the event
    // needs a real order behind it - one Coins item with an EA account, and a
    // pricing run to budget against.
    static $sequence = 1000;
    $sequence++;

    $order = placeableOrder('AUT-PUB-'.$sequence);
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

// The retirement used to overwrite `last_error` with `max_attempts_exceeded`,
// which threw away the only column saying WHY the ten attempts failed - and
// that column is what the alarm sweep grades a stranded paid order on. "Ten
// attempts, failed" was already on the row twice over, in `attempts` and in
// `status`.
test('retiring an event keeps the reason its attempts actually failed for', function (): void {
    $event = publisherEvent([
        'attempts' => 10,
        'available_at' => now()->subMinute(),
        'last_error' => 'budget_unavailable',
    ]);

    $logged = [];
    Log::listen(function ($log) use (&$logged): void {
        $logged[] = $log;
    });

    $this->artisan('orders:publish-paid-events')->assertSuccessful();

    expect($event->fresh()->status)->toBe('failed')
        ->and($event->fresh()->last_error)->toBe('budget_unavailable');

    $retirement = collect($logged)->first(
        fn ($log): bool => $log->level === 'error' && str_contains((string) $log->message, 'retired'),
    );
    expect($retirement?->context['reason'] ?? null)->toBe('budget_unavailable');
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

test('a deferred delivery exits zero: the row carries the reason, the scheduler does not log a failure', function (): void {
    $event = publisherEvent(['available_at' => now()->subMinute()]);
    Http::fake(['https://n8n.example.test/*' => Http::response('', 503)]);

    $this->artisan('orders:publish-paid-events')
        ->expectsOutputToContain('Processed 1 paid-order event(s); 1 deferred.')
        ->assertSuccessful();

    $event->refresh();
    expect($event->status)->toBe('pending')
        ->and($event->last_error)->toBe('delivery_failed')
        ->and($event->attempts)->toBe(1);
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
