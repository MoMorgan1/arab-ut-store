<?php

use App\Console\Commands\ExpireAbandonedCheckouts;
use App\Console\Commands\MaintainChatConversations;
use App\Console\Commands\PollFulfillmentJobs;
use App\Console\Commands\PruneObservationGaps;
use App\Console\Commands\PrunePricingHistory;
use App\Console\Commands\PublishChallengeReadyEvents;
use App\Console\Commands\PublishOrderPaidEvents;
use App\Console\Commands\PurgeDeadCancelledOrders;
use App\Console\Commands\PurgeGuestCartClaims;
use App\Console\Commands\PurgeRemovedCartItems;
use App\Console\Commands\RecoverStaleAgentTurns;
use App\Console\Commands\RefreshDisplayExchangeRates;
use App\Console\Commands\SweepFulfillmentAlarms;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(RefreshDisplayExchangeRates::class)->daily();
Schedule::command(PurgeGuestCartClaims::class)->hourly()->withoutOverlapping();
Schedule::command(PurgeRemovedCartItems::class)->hourly()->withoutOverlapping();
Schedule::command(PublishOrderPaidEvents::class)->everyMinute()->withoutOverlapping();
Schedule::command(PublishChallengeReadyEvents::class)->everyMinute()->withoutOverlapping();
Schedule::command(MaintainChatConversations::class)->hourly()->withoutOverlapping();
Schedule::command(RecoverStaleAgentTurns::class)->everyMinute()->withoutOverlapping();
Schedule::command(ExpireAbandonedCheckouts::class)->hourly()->withoutOverlapping();
Schedule::command(PurgeDeadCancelledOrders::class)->hourly()->withoutOverlapping();
Schedule::command(PrunePricingHistory::class)->dailyAt('03:20')->withoutOverlapping();

// The poller writes one row per landed observation, so this is the only
// fulfillment table that grows with time rather than with sales. Its own
// minute, ten past the pricing prune, so two chunked deletes are not competing
// for the same shared-hosting IO.
Schedule::command(PruneObservationGaps::class)->dailyAt('03:30')->withoutOverlapping();

// Every five minutes rather than every minute: the shortest silence it can
// report is fifteen minutes old, so a minute's resolution would buy nothing
// and cost three extra table scans a minute on shared hosting.
Schedule::command(SweepFulfillmentAlarms::class)->everyFiveMinutes()->withoutOverlapping();

// No withoutOverlapping() here on purpose: the command's own Cache::lock is the
// lease. The default mutex lasts a day, so a tick that was OOM-killed or left
// behind after a reboot would silently stop every poll for twenty-four hours;
// the command's lock carries the deadline as its TTL instead, so a killed
// process frees it in under two minutes. runInBackground lets schedule:run
// return before a slow supplier, so the next minute's cron is not blocked.
Schedule::command(PollFulfillmentJobs::class)->everyMinute()->runInBackground();

/*
 * Queued work is drained by the scheduler rather than a long-running worker.
 *
 * The host runs `schedule:run` every minute by cron and has no supervisor, so a
 * daemon worker would have nothing to keep it alive. `--stop-when-empty` lets
 * each run finish once the queue drains, `--max-time` keeps a busy run from
 * overlapping the next minute, and `withoutOverlapping` means a long job never
 * gets a second worker on top of it.
 *
 * Without this, queued mail - the order receipt among it - sits in the jobs
 * table and is never delivered.
 */
Schedule::command('queue:work', [
    '--stop-when-empty',
    '--max-time=55',
    '--tries=3',
    '--backoff=30',
    // The mutex expires after two minutes rather than Laravel's default day:
    // runInBackground releases it through schedule:finish, which never runs if
    // the worker is OOM-killed or the box reboots mid-run. A --max-time of 55
    // seconds means a live run can never need longer than this, so a stale
    // mutex cannot silently stop all mail for twenty-four hours.
])->everyMinute()->withoutOverlapping(2)->runInBackground();
