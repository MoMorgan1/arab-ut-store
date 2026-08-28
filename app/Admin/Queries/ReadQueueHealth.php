<?php

namespace App\Admin\Queries;

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
     *     failedJobs: int,
     *     latestFailure: null|array{name: string, failedAt: string},
     *     stalledJobs: int,
     *     oldestQueuedAt: null|string,
     * }
     */
    public function read(): array
    {
        $latest = DB::table('failed_jobs')->orderByDesc('failed_at')->orderByDesc('id')->first();

        // available_at, not created_at: a deliberately delayed job is waiting,
        // not stalled, and must not raise an alarm before its time arrives.
        $stalledBefore = now()->getTimestamp() - self::STALLED_AFTER_SECONDS;
        $oldestQueuedAt = DB::table('jobs')->where('available_at', '<=', $stalledBefore)->min('available_at');

        return [
            'failedJobs' => DB::table('failed_jobs')->count(),
            'latestFailure' => $latest === null ? null : [
                // The class name only. The stored exception carries whatever
                // the failure printed - SMTP failures print the account and
                // sometimes the credential - and none of that belongs in an
                // Inertia prop.
                'name' => $this->displayName((string) $latest->payload),
                'failedAt' => now()->parse($latest->failed_at)->toIso8601String(),
            ],
            'stalledJobs' => DB::table('jobs')->where('available_at', '<=', $stalledBefore)->count(),
            'oldestQueuedAt' => $oldestQueuedAt === null
                ? null
                : now()->setTimestamp((int) $oldestQueuedAt)->toIso8601String(),
        ];
    }

    private function displayName(string $payload): string
    {
        $decoded = json_decode($payload, true);
        $name = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : 'Unknown job';
    }
}
