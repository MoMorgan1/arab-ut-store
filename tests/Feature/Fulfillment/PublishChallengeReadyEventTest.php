<?php

use App\Actions\Fulfillment\ComposePlacementRequest;
use App\Actions\Fulfillment\EnqueueChallengeSolve;
use App\Actions\Fulfillment\PublishChallengeReadyEvent;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\IntegrationEvent;
use App\Models\OrderItem;
use App\Models\SecretAccessLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.n8n.solve_challenge_url', 'https://n8n.example.test/webhook/arab-ut-solve-challenge');
    config()->set('services.n8n.solve_challenge_key', 'challenge-publisher');
    config()->set('services.n8n.solve_challenge_secret', str_repeat('c', 48));
    Http::preventStrayRequests();
});

/** @return array{OrderItem, FulfillmentJob, IntegrationEvent} */
function fundedChallenge(?array $secretPayload = null): array
{
    $order = paidOrder('AUT-SOLVE-1001');
    $item = challengeItem($order, ['quantity' => 2], $secretPayload ?? eaAccount(['ea_email' => 'sbc@example.test']));
    $job = fundedChallengeJob($item, ['status' => FulfillmentStatus::Completed, 'completed_at' => now()]);
    $event = app(EnqueueChallengeSolve::class)->execute($item, $job);

    expect($event)->toBeInstanceOf(IntegrationEvent::class);

    return [$item, $job, $event];
}

function solveAcknowledged(): void
{
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);
}

/** @return array<string, mixed> */
function solveBody(): array
{
    $body = [];
    Http::assertSent(function (Request $request) use (&$body): bool {
        $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

        return true;
    });

    return $body;
}

test('the solve request carries the set, the funding placement and the EA account, signed with its own key pair', function (): void {
    [$item, $job, $event] = fundedChallenge();
    solveAcknowledged();

    expect(app(PublishChallengeReadyEvent::class)->execute($event))->toBeTrue();

    $event->refresh();
    expect($event->status)->toBe('processed')
        ->and($event->attempts)->toBe(1)
        ->and($event->last_error)->toBeNull();

    Http::assertSent(function (Request $request) use ($event): bool {
        $raw = $request->body();
        $timestamp = $request->header('X-ArabUT-Timestamp')[0] ?? '';
        $expected = hash_hmac('sha256', $timestamp."\n".$event->event_id."\n".$raw, str_repeat('c', 48));

        return $request->url() === 'https://n8n.example.test/webhook/arab-ut-solve-challenge'
            && $request->method() === 'POST'
            && $request->hasHeader('X-ArabUT-Key', 'challenge-publisher')
            && $request->hasHeader('X-ArabUT-Event', $event->event_id)
            && $request->hasHeader('X-ArabUT-Signature', $expected);
    });

    $body = solveBody();
    expect($body['eventType'])->toBe('challenge.ready')
        ->and($body['schemaVersion'])->toBe(1)
        ->and($body['data']['order_number'])->toBe('AUT-SOLVE-1001')
        ->and($body['data']['order_item_public_id'])->toBe($item->public_id)
        ->and($body['data']['customer_name'])->toBe('Fahad Al-Otaibi')
        ->and($body['data'])->not->toHaveKeys(['customer_phone', 'customer_email', 'items'])
        ->and($body['data']['item'])->toBe([
            'order_item_public_id' => $item->public_id,
            'service' => 'sbc',
            'platform' => 'playstation',
            'supplier_platform' => 'PS',
            'quantity' => 2,
            // completion_count 2 × quantity 2
            'sbc' => ['set_id' => 412, 'times_to_solve' => 4],
            'funding' => ['supplier' => 'utt', 'supplier_order_id' => '574339'],
            'account' => [
                'ea_email' => 'sbc@example.test',
                'ea_password' => 'safe password',
                'backup_codes' => ['11111111', '22222222', '33333333'],
                'current_balance' => 350_000,
                'credential_version' => 1,
            ],
        ])
        ->and($body['data']['item'])->not->toHaveKey('budget');

    $access = SecretAccessLog::query()->sole();
    expect($access->purpose)->toBe(ComposePlacementRequest::CHALLENGE_ACCESS_PURPOSE)
        ->and($access->user_id)->toBeNull()
        ->and($access->order_item_secret_id)->toBe($item->secret->id);

    // Processed rows are never sent twice.
    expect(app(PublishChallengeReadyEvent::class)->execute($event->fresh()))->toBeTrue();
    Http::assertSentCount(1);
});

test('a solve reported meanwhile finishes the row without a request', function (): void {
    [$item, $job, $event] = fundedChallenge();
    FulfillmentPlacement::query()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => '0a1b2c3d-0000-4000-8000-000000000002',
        'supplier_challenge_ids' => ['0a1b2c3d-0000-4000-8000-000000000002'],
        'idempotency_key' => 'fulfillment-placement:'.$item->public_id.':challenge',
        'placed_at' => now(),
    ]);

    expect(app(PublishChallengeReadyEvent::class)->execute($event))->toBeTrue()
        ->and($event->fresh()->status)->toBe('processed');

    Http::assertNothingSent();
    expect(SecretAccessLog::query()->count())->toBe(0);
});

test('a request that cannot be composed is released with the reason, and no secret is read for nothing', function (callable $arrange, string $reason): void {
    [$item, $job, $event] = fundedChallenge();
    $arrange($item, $job);

    expect(app(PublishChallengeReadyEvent::class)->execute($event))->toBeFalse();

    $event->refresh();
    expect($event->status)->toBe('pending')
        ->and($event->attempts)->toBe(1)
        ->and($event->last_error)->toBe($reason)
        ->and($event->available_at?->isFuture())->toBeTrue();

    Http::assertNothingSent();
    expect(SecretAccessLog::query()->count())->toBe(0);
})->with([
    'the EA account was purged' => [
        fn (OrderItem $item): mixed => $item->secret->delete(),
        'credentials_missing',
    ],
    'the funding placement is gone' => [
        fn (OrderItem $item, FulfillmentJob $job): mixed => $job->placements()->delete(),
        'funding_missing',
    ],
]);

test('n8n not acknowledging releases the row for retry and persists nothing from the answer', function (): void {
    [, , $event] = fundedChallenge();
    Http::fake(['https://n8n.example.test/*' => Http::response(['error' => 'set expired'], 503)]);

    expect(app(PublishChallengeReadyEvent::class)->execute($event))->toBeFalse();

    $event->refresh();
    expect($event->status)->toBe('pending')
        ->and($event->last_error)->toBe('delivery_failed')
        ->and(json_encode($event->toArray()))->not->toContain('set expired');
});

test('missing publisher configuration fails closed before the network', function (): void {
    [, , $event] = fundedChallenge();
    config()->set('services.n8n.solve_challenge_url', null);

    expect(app(PublishChallengeReadyEvent::class)->execute($event))->toBeFalse()
        ->and($event->fresh()->last_error)->toBe('delivery_failed');

    Http::assertNothingSent();
});
