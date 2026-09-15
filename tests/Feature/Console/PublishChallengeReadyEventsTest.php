<?php

use App\Actions\Fulfillment\EnqueueChallengeSolve;
use App\Enums\FulfillmentStatus;
use App\Models\IntegrationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function challengeEvent(array $overrides = []): IntegrationEvent
{
    static $sequence = 2000;
    $sequence++;

    $order = paidOrder('AUT-CHL-'.$sequence);
    $item = challengeItem($order, [], eaAccount());
    $job = fundedChallengeJob($item, ['status' => FulfillmentStatus::Completed, 'completed_at' => now()]);
    $event = app(EnqueueChallengeSolve::class)->execute($item, $job);

    $event->forceFill($overrides)->save();

    return $event->fresh();
}

beforeEach(function (): void {
    config()->set('services.n8n.solve_challenge_url', 'https://n8n.example.test/webhook/arab-ut-solve-challenge');
    config()->set('services.n8n.solve_challenge_key', 'challenge-publisher');
    config()->set('services.n8n.solve_challenge_secret', str_repeat('c', 48));
    Http::preventStrayRequests();
});

test('a due challenge event is delivered and a deferred one exits zero', function (): void {
    $delivered = challengeEvent(['available_at' => now()->subMinute()]);
    $notYet = challengeEvent(['available_at' => now()->addHour()]);
    // n8n acknowledges the first send and is down for the second.
    Http::fake(['https://n8n.example.test/*' => Http::sequence()
        ->push(['data' => ['acknowledged' => true]])
        ->push('', 503)]);

    $this->artisan('orders:publish-challenge-events')
        ->expectsOutputToContain('Processed 1 challenge event(s); 0 deferred.')
        ->assertSuccessful();

    expect($delivered->fresh()->status)->toBe('processed')
        ->and($notYet->fresh()->status)->toBe('pending');

    $notYet->forceFill(['available_at' => now()->subMinute()])->save();

    $this->artisan('orders:publish-challenge-events')
        ->expectsOutputToContain('Processed 1 challenge event(s); 1 deferred.')
        ->assertSuccessful();

    expect($notYet->fresh()->last_error)->toBe('delivery_failed')
        ->and($notYet->fresh()->status)->toBe('pending');
});

test('a challenge event at the attempt ceiling is retired and requeued by the same command as a paid one', function (): void {
    $event = challengeEvent(['attempts' => 10, 'available_at' => now()->subMinute()]);

    $logged = [];
    Log::listen(function ($log) use (&$logged): void {
        $logged[] = $log;
    });

    $this->artisan('orders:publish-challenge-events')->assertSuccessful();

    expect($event->fresh()->status)->toBe('failed')
        ->and($event->fresh()->last_error)->toBe('max_attempts_exceeded');

    $retirement = collect($logged)->first(
        fn ($log): bool => $log->level === 'error' && str_contains((string) $log->message, 'Challenge event retired'),
    );
    expect($retirement)->not->toBeNull()
        ->and($retirement->context['order_number'])->toBe('AUT-CHL-2003');

    $this->artisan('orders:requeue-paid-event', ['event_id' => $event->event_id])->assertSuccessful();

    expect($event->fresh()->status)->toBe('pending')
        ->and($event->fresh()->attempts)->toBe(0);
});
