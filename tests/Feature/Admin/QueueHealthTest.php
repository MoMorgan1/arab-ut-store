<?php

use App\Admin\Presenters\AdminOverviewPage;
use App\Admin\Queries\ReadQueueHealth;
use App\Enums\UserRole;
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

function queueAJob(CarbonInterface $availableAt): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\Notifications\OrderPaidNotification']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => $availableAt->getTimestamp(),
        'created_at' => $availableAt->getTimestamp(),
    ]);
}

test('a quiet queue reports nothing', function (): void {
    $health = app(ReadQueueHealth::class)->read();

    expect($health)->toBe([
        'failedJobs' => 0,
        'latestFailure' => null,
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

    $props = app(AdminOverviewPage::class)->for(
        User::factory()->create(['role' => UserRole::Admin]),
        'en',
        7,
    );

    expect($props['queueHealth']['failedJobs'])->toBe(1);
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
