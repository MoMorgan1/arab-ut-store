<?php

namespace App\Admin\Queries;

use App\Models\FulfillmentAlarm;
use App\Models\IntegrationEvent;
use Illuminate\Support\Facades\DB;

/**
 * Whether background work is moving.
 *
 * The store sent no email for months and nothing said so; the order receipt
 * then failed three times on production and sat in failed_jobs, silent again.
 * Both failures were invisible because nothing ever looked at these two
 * tables, and a stopped queue looks exactly like a quiet day.
 *
 * Two conditions, because they fail differently. Rows in failed_jobs mean the
 * work was tried and refused. Rows sitting in jobs mean nothing tried at all -
 * the scheduler cron is dead, or a stale mutex is holding the worker off - and
 * that one leaves failed_jobs empty while every receipt silently stops.
 *
 * A third failure mode lives outside both tables: the order-paid outbox in
 * integration_events is drained by its own command, not the queue worker, so
 * an event that exhausted every delivery attempt sits there as failed while
 * failed_jobs stays empty. The panel counts those rows too.
 *
 * A fourth is quieter still, and is the reason fulfillment_alarms exists: an
 * item can be paid for, published, acknowledged, and simply never placed - no
 * failed job, no failed event, nothing waiting anywhere. Only the alarm sweep
 * knows about those, so the panel reads its open rows here.
 */
final class ReadQueueHealth
{
    /**
     * The scheduler runs a worker every minute with --max-time=55, so anything
     * still waiting after five minutes has missed several turns and is not
     * merely busy.
     */
    private const STALLED_AFTER_SECONDS = 300;

    /**
     * @return array{
     *     monitored: bool,
     *     connection: string,
     *     failedJobs: int,
     *     latestFailure: null|array{name: string, failedAt: string},
     *     failedEvents: int,
     *     stalledJobs: int,
     *     oldestQueuedAt: null|string,
     *     silentItems: int,
     *     oldestSilenceAt: null|string,
     * }
     */
    public function read(): array
    {
        // The outbox is the application's own table, read through the model,
        // so its count is honest no matter which queue driver is configured.
        $failedEvents = IntegrationEvent::query()->where('status', 'failed')->count();

        // Also ours, and also honest on every queue driver: an alarm is open
        // until the sweep sees its silence end.
        $silence = $this->silence();

        $connection = (string) config('queue.default');

        // Only the database queue keeps its work in these tables. On sync,
        // redis or sqs they stay empty forever, and reporting "nothing failed"
        // would be the same silent green that hid MAIL_MAILER=log for months.
        if ($connection !== 'database') {
            return [
                'monitored' => false,
                'connection' => $connection,
                'failedJobs' => 0,
                'latestFailure' => null,
                'failedEvents' => $failedEvents,
                'stalledJobs' => 0,
                'oldestQueuedAt' => null,
                'silentItems' => $silence['total'],
                'oldestSilenceAt' => $silence['oldest'],
            ];
        }

        // Only payload and failed_at: the exception column carries whatever the
        // failure printed, and an SMTP refusal prints the account it tried to
        // authenticate as. It has no reason to enter PHP memory at all.
        $latest = DB::table('failed_jobs')
            ->select('payload', 'failed_at')
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->first();

        $waiting = $this->waiting();

        return [
            'monitored' => true,
            'connection' => $connection,
            'failedJobs' => DB::table('failed_jobs')->count(),
            'latestFailure' => $latest === null ? null : [
                'name' => $this->displayName((string) $latest->payload),
                'failedAt' => now()->parse($latest->failed_at)->toIso8601String(),
            ],
            'failedEvents' => $failedEvents,
            'stalledJobs' => $waiting['total'],
            'oldestQueuedAt' => $waiting['oldest'] === null
                ? null
                : now()->setTimestamp($waiting['oldest'])->toIso8601String(),
            'silentItems' => $silence['total'],
            'oldestSilenceAt' => $silence['oldest'],
        ];
    }

    /**
     * Rows the queue would hand to a worker right now, and has not.
     *
     * The predicate is Laravel's own definition of a takeable job
     * (DatabaseQueue::isAvailable and ::isReservedButExpired), because anything
     * looser lies in both directions: a job being worked on keeps its original
     * available_at, so counting it would blame a healthy cron, while a job
     * whose worker died keeps its reserved_at, so ignoring it would hide the
     * failure entirely. Counted in one pass - two scans of the same rows to
     * answer two questions about them is a waste on the day it matters.
     *
     * @return array{total: int, oldest: int|null}
     */
    private function waiting(): array
    {
        $now = now()->getTimestamp();
        $stalledBefore = $now - self::STALLED_AFTER_SECONDS;
        $abandonedBefore = $now - (int) config('queue.connections.database.retry_after', 90);

        $row = DB::table('jobs')
            ->selectRaw('count(*) as total, min(available_at) as oldest')
            // available_at, not created_at: a deliberately delayed job is
            // waiting for its own time, not stalled, and must not raise an
            // alarm before it arrives.
            ->where('available_at', '<=', $stalledBefore)
            ->where(function ($query) use ($abandonedBefore): void {
                $query->whereNull('reserved_at')
                    ->orWhere('reserved_at', '<=', $abandonedBefore);
            })
            ->first();

        $oldest = $row->oldest ?? null;

        return [
            'total' => (int) ($row->total ?? 0),
            'oldest' => $oldest === null ? null : (int) $oldest,
        ];
    }

    /**
     * Paid items nothing is working on, and how long the oldest has been that
     * way. One pass, for the same reason the queue scan is one pass.
     *
     * @return array{total: int, oldest: string|null}
     */
    private function silence(): array
    {
        $row = FulfillmentAlarm::query()
            ->whereNull('resolved_at')
            ->selectRaw('count(*) as total, min(raised_at) as oldest')
            ->first();

        $oldest = $row?->getAttribute('oldest');

        return [
            'total' => (int) ($row?->getAttribute('total') ?? 0),
            'oldest' => $oldest === null ? null : now()->parse($oldest)->toIso8601String(),
        ];
    }

    private function displayName(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $name = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : 'Unknown job';
    }
}
