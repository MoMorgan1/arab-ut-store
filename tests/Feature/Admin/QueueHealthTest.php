<?php

use App\Admin\Presenters\AdminOverviewPage;
use App\Admin\Queries\ReadQueueHealth;
use App\Enums\UserRole;
use App\Models\IntegrationEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The store sent no email for months and nothing said so. The receipt then
 * failed three times on production and sat in failed_jobs, silent again. A
 * queue that stops is indistinguishable from a quiet day unless something
 * looks at these two tables.
 */
beforeEach(function (): void {
    // The suite runs on the sync queue; these cases are about the database one.
    config()->set('queue.default', 'database');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function queueAFailure(string $displayName, CarbonInterface $failedAt, string $exception = 'RuntimeException: nothing to see'): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $displayName, 'job' => 'Illuminate\Queue\CallQueuedHandler@call']),
        'exception' => $exception,
        'failed_at' => $failedAt,
    ]);
}

function queueAJob(CarbonInterface $availableAt, ?CarbonInterface $reservedAt = null): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\Notifications\OrderPaidNotification']),
        'attempts' => 0,
        'reserved_at' => $reservedAt?->getTimestamp(),
        'available_at' => $availableAt->getTimestamp(),
        'created_at' => $availableAt->getTimestamp(),
    ]);
}

function queueAnIntegrationEvent(string $status, string $suffix): void
{
    IntegrationEvent::create([
        'event_id' => (string) Str::ulid(),
        'event_type' => 'order.paid',
        'aggregate_type' => 'order',
        'aggregate_id' => (string) Str::ulid(),
        'payload' => ['order_number' => 'AUT-HEALTH-'.$suffix],
        'status' => $status,
        'idempotency_key' => 'order-paid:health-'.$suffix,
        'attempts' => 10,
    ]);
}

test('a quiet queue reports nothing', function (): void {
    $health = app(ReadQueueHealth::class)->read();

    expect($health)->toBe([
        'monitored' => true,
        'connection' => 'database',
        'failedJobs' => 0,
        'latestFailure' => null,
        'failedEvents' => 0,
        'stalledJobs' => 0,
        'oldestQueuedAt' => null,
    ]);
});

test('a failed job is counted and named', function (): void {
    Carbon::setTestNow('2026-08-28T10:00:00Z');
    queueAFailure('App\Notifications\OrderPaidNotification', now()->subMinutes(20));
    queueAFailure('App\Notifications\SupportReplyNotification', now()->subMinutes(2));

    $health = app(ReadQueueHealth::class)->read();

    expect($health['failedJobs'])->toBe(2)
        ->and($health['latestFailure']['name'])->toBe('App\Notifications\SupportReplyNotification')
        ->and($health['latestFailure']['failedAt'])->toBe('2026-08-28T09:58:00+00:00');
});

test('integration events the publisher retired count as failed too', function (): void {
    // The order-paid outbox never enters jobs or failed_jobs: its publisher is
    // a command, so a row that exhausted every delivery attempt would be
    // invisible here unless integration_events was read directly.
    queueAnIntegrationEvent('failed', '1001');
    queueAnIntegrationEvent('failed', '1002');
    queueAnIntegrationEvent('processed', '1003');

    $health = app(ReadQueueHealth::class)->read();

    expect($health['failedEvents'])->toBe(2);
});

test('the exception text never reaches the dashboard', function (): void {
    queueAFailure(
        'App\Notifications\OrderPaidNotification',
        now(),
        'Swift_TransportException: Failed to authenticate as info@arab-ut.com with password hunter2',
    );

    $health = app(ReadQueueHealth::class)->read();

    expect(json_encode($health))->not->toContain('hunter2')
        ->and(json_encode($health))->not->toContain('arab-ut.com');
});

test('a job the worker has not taken in five minutes counts as stalled', function (): void {
    Carbon::setTestNow('2026-08-28T10:00:00Z');
    queueAJob(now()->subMinutes(6));

    $health = app(ReadQueueHealth::class)->read();

    expect($health['stalledJobs'])->toBe(1)
        ->and($health['oldestQueuedAt'])->toBe('2026-08-28T09:54:00+00:00');
});

test('ordinary traffic does not raise the alarm', function (): void {
    Carbon::setTestNow('2026-08-28T10:00:00Z');
    queueAJob(now()->subSeconds(30));

    $health = app(ReadQueueHealth::class)->read();

    expect($health['stalledJobs'])->toBe(0)
        ->and($health['oldestQueuedAt'])->toBeNull();
});

test('a job whose delay has not elapsed is waiting, not stalled', function (): void {
    Carbon::setTestNow('2026-08-28T10:00:00Z');
    queueAJob(now()->addMinutes(30));

    $health = app(ReadQueueHealth::class)->read();

    expect($health['stalledJobs'])->toBe(0);
});

test('an admin sees queue health on the overview', function (): void {
    queueAFailure('App\Notifications\OrderPaidNotification', now());
    queueAnIntegrationEvent('failed', 'overview-1001');

    $props = app(AdminOverviewPage::class)->for(
        User::factory()->create(['role' => UserRole::Admin]),
        'en',
        7,
    );

    expect($props['queueHealth']['failedJobs'])->toBe(1)
        ->and($props['queueHealth']['failedEvents'])->toBe(1);
});

test('staff without the settings permission are not shown it', function (): void {
    queueAFailure('App\Notifications\OrderPaidNotification', now());

    $props = app(AdminOverviewPage::class)->for(
        User::factory()->create(['role' => UserRole::Staff]),
        'en',
        7,
    );

    expect($props['queueHealth'])->toBeNull();
});

test('a job being worked on right now is in flight, not stalled', function (): void {
    // Laravel never moves available_at when it reserves a row, so an old
    // available_at says nothing about whether anyone is working the job.
    Carbon::setTestNow('2026-08-28T10:00:00Z');
    queueAJob(now()->subMinutes(20), reservedAt: now()->subSeconds(10));

    expect(app(ReadQueueHealth::class)->read()['stalledJobs'])->toBe(0);
});

test('a job whose worker died is stalled again once the queue would retake it', function (): void {
    // retry_after seconds after reservation the queue itself treats the row as
    // abandoned and re-reserves it. Past that line it is waiting, not in flight.
    Carbon::setTestNow('2026-08-28T10:00:00Z');
    $retryAfter = (int) config('queue.connections.database.retry_after');
    queueAJob(now()->subMinutes(20), reservedAt: now()->subSeconds($retryAfter + 1));

    expect(app(ReadQueueHealth::class)->read()['stalledJobs'])->toBe(1);
});

test('a queue this dashboard cannot see reports itself blind, not healthy', function (): void {
    // MAIL_MAILER=log is why this feature exists. QUEUE_CONNECTION=sync is the
    // same one-line mistake, and it would leave both tables empty forever.
    config()->set('queue.default', 'sync');
    // The outbox is the application's own table, so its count stays honest
    // even when the queue tables themselves are unreadable.
    queueAnIntegrationEvent('failed', 'sync-1001');

    $health = app(ReadQueueHealth::class)->read();

    expect($health['monitored'])->toBeFalse()
        ->and($health['connection'])->toBe('sync')
        ->and($health['failedEvents'])->toBe(1);
});

test('an admin request carries the queue prop without the exception text', function (): void {
    queueAFailure('App\Notifications\OrderPaidNotification', now());

    $props = app(AdminOverviewPage::class)->for(
        User::factory()->create(['role' => UserRole::Admin]),
        'en',
        7,
    );

    expect(json_encode($props['queueHealth']))->not->toContain('nothing to see');
});
