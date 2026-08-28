<?php

use App\Console\Commands\ExpireAbandonedCheckouts;
use App\Console\Commands\MaintainChatConversations;
use App\Console\Commands\PrunePricingHistory;
use App\Console\Commands\PublishOrderPaidEvents;
use App\Console\Commands\PurgeGuestCartClaims;
use App\Console\Commands\RecoverStaleAgentTurns;
use App\Console\Commands\RefreshDisplayExchangeRates;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(RefreshDisplayExchangeRates::class)->daily();
Schedule::command(PurgeGuestCartClaims::class)->hourly()->withoutOverlapping();
Schedule::command(PublishOrderPaidEvents::class)->everyMinute()->withoutOverlapping();
Schedule::command(MaintainChatConversations::class)->hourly()->withoutOverlapping();
Schedule::command(RecoverStaleAgentTurns::class)->everyMinute()->withoutOverlapping();
Schedule::command(ExpireAbandonedCheckouts::class)->hourly()->withoutOverlapping();
Schedule::command(PrunePricingHistory::class)->dailyAt('03:20')->withoutOverlapping();

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
