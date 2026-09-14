<?php

namespace App\Console\Commands;

use App\Actions\Fulfillment\ObservationOutcome;
use App\Actions\Fulfillment\ObserveFulfillmentJob;
use App\Enums\FulfillmentStatus;
use App\Enums\ObservationResult;
use App\Enums\OrderStatus;
use App\Models\FulfillmentJob;
use App\Models\OrderItem;
use App\Suppliers\Exceptions\SupplierNotConfigured;
use App\Suppliers\Exceptions\SupplierUnavailable;
use App\Suppliers\SupplierGuard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The scheduled read loop that advances fulfillment jobs in the background.
 *
 * A customer opening their order page is what used to move a job, so an order
 * nobody reopened never progressed. This command reads due jobs on a schedule
 * instead, and it is the place the decision to stay in Laravel rather than n8n
 * is measured: every tick logs one summary line carrying supplier latency
 * percentiles, which is data n8n would have kept to itself.
 */
final class PollFulfillmentJobs extends Command
{
    /**
     * How many jobs one band hands over at a time.
     *
     * The loop selects again as soon as a batch is done, so this costs nothing
     * on a quiet store and stops a backlog from loading every due row - with its
     * item, order and placements - into one process on shared hosting. A tick
     * cannot read anywhere near this many inside its deadline anyway.
     */
    private const BATCH = 100;

    protected $signature = 'fulfillment:poll {--deadline=} {--limit=}';

    protected $description = 'Read due fulfillment jobs from their suppliers and advance their poll schedule';

    public function handle(ObserveFulfillmentJob $observe, SupplierGuard $guard): int
    {
        $deadline = $this->deadlineSeconds();
        $started = CarbonImmutable::now();

        // The tick lease is the crash-recoverable replacement for
        // withoutOverlapping(): a killed process frees this in under two
        // minutes because the TTL is the deadline, not Laravel's day-long
        // default mutex. A held lock means the previous tick is still running.
        $lock = Cache::lock('fulfillment:poll', $deadline + 10);

        if (! $lock->get()) {
            Log::info('Fulfillment poll skipped: the previous tick still holds the lease.', [
                'lease_skipped' => true,
            ]);

            return self::SUCCESS;
        }

        try {
            $this->runTick($observe, $guard, $started, $deadline);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function runTick(ObserveFulfillmentJob $observe, SupplierGuard $guard, CarbonImmutable $started, int $deadline): void
    {
        // Real seconds on both sides of the comparison. The check below reads the
        // wall clock, so deriving the deadline from a clock a test can freeze
        // would let the two disagree and end the tick before it began.
        $deadlineAt = time() + $deadline;
        $limit = $this->limit();

        /** @var array<string, int> $counts */
        $counts = [
            ObservationResult::Observed->value => 0,
            ObservationResult::Unreadable->value => 0,
            ObservationResult::Unavailable->value => 0,
            ObservationResult::NotConfigured->value => 0,
            ObservationResult::Busy->value => 0,
        ];

        $circuitSkipped = 0;
        $attempted = 0;
        $passes = 0;
        $deadlineHit = false;

        /** @var list<float> $latencies */
        $latencies = [];

        /** @var array<string, array{attempted: int, observed: int, failed: int, latencies: list<float>}> $perSupplier */
        $perSupplier = [];

        /** @var array<string, CarbonImmutable> $circuitOpenUntil */
        $circuitOpenUntil = [];

        // The scheduler fires once a minute but the fast band runs on a 25-second
        // cadence, so one invocation loops: select, read, select again, until the
        // deadline or until nothing is due.
        while (true) {
            if ($this->deadlineReached($deadlineAt)) {
                $deadlineHit = true;

                break;
            }

            $batch = $this->selectDueJobs(CarbonImmutable::now());

            if ($batch->isEmpty()) {
                break;
            }

            $passes++;

            foreach ($batch as $job) {
                if ($limit > 0 && $attempted >= $limit) {
                    break 2;
                }

                if ($this->deadlineReached($deadlineAt)) {
                    $deadlineHit = true;

                    break 2;
                }

                $item = $job->orderItem;

                // An orphaned job row (order item deleted out from under it) has
                // nothing to read; it is left alone rather than crashing the tick.
                if (! $item instanceof OrderItem) {
                    continue;
                }

                $order = $item->order;
                $supplier = $job->supplier;

                // A supplier whose circuit opened earlier this tick is left alone
                // for the rest of it: the failure was just measured, so re-reading
                // it would only hammer a supplier already in its cooldown.
                if ($supplier !== null && isset($circuitOpenUntil[$supplier->value])) {
                    $this->deferForCircuit($job, $circuitOpenUntil[$supplier->value]);
                    $circuitSkipped++;

                    continue;
                }

                if ($supplier !== null) {
                    $availableAt = $guard->availableAt($supplier);

                    if ($availableAt !== null) {
                        $circuitOpenUntil[$supplier->value] = $availableAt;
                        $this->deferForCircuit($job, $availableAt);
                        $circuitSkipped++;

                        continue;
                    }
                }

                $token = Str::random(32);

                // Claim the job with one conditional update so two overlapping ticks
                // cannot take the same job; only proceed when exactly one row moved.
                if (! $this->claimLease($job->id, $token)) {
                    continue;
                }

                $attempted++;

                // One job's failure must never abort the tick. The supplier exceptions
                // are turned into outcomes; a genuinely unexpected error is logged and
                // backed off like an unavailable read, so a broken job is not retried
                // in a tight loop for the rest of this tick.
                // The lease covers the read and the schedule the read produces, not
                // the read alone. Releasing between them leaves the job due, unleased
                // and already answered for a moment, which is long enough for another
                // reader to ask the supplier the same question again.
                try {
                    try {
                        $outcome = $observe->execute($job, $item, $order);
                    } catch (SupplierUnavailable $exception) {
                        $outcome = new ObservationOutcome(ObservationResult::Unavailable, $exception->reason, 0.0);
                    } catch (SupplierNotConfigured $exception) {
                        $outcome = new ObservationOutcome(ObservationResult::NotConfigured, $exception->reason, 0.0);
                    } catch (Throwable $exception) {
                        Log::error('Fulfillment poll errored while reading job {job_id}', [
                            'job_id' => $job->id,
                            'exception' => $exception->getMessage(),
                        ]);

                        $outcome = new ObservationOutcome(ObservationResult::Unavailable, null, 0.0);
                    }

                    $counts[$outcome->result->value] = ($counts[$outcome->result->value] ?? 0) + 1;

                    if ($outcome->latencyMs > 0.0) {
                        $latencies[] = $outcome->latencyMs;
                    }

                    if ($supplier !== null) {
                        $bucket = $perSupplier[$supplier->value] ?? ['attempted' => 0, 'observed' => 0, 'failed' => 0, 'latencies' => []];
                        $bucket['attempted']++;

                        if ($outcome->result === ObservationResult::Observed) {
                            $bucket['observed']++;
                        } elseif (in_array($outcome->result, [ObservationResult::Unavailable, ObservationResult::NotConfigured], true)) {
                            $bucket['failed']++;
                        }

                        if ($outcome->latencyMs > 0.0) {
                            $bucket['latencies'][] = $outcome->latencyMs;
                        }

                        $perSupplier[$supplier->value] = $bucket;
                    }

                    $this->advancePoll($job, $outcome);
                } finally {
                    // Released on every path, including a throw, and only when the row
                    // still carries our token so a lease that expired and was re-taken
                    // by another tick is never stolen back.
                    $this->releaseLease($job->id, $token);
                }
            }
        }

        $this->logSummary($counts, $circuitSkipped, $deadlineHit, $passes, $attempted, $started, $latencies, $perSupplier);
    }

    /**
     * Selects due jobs, the fast (attention) band first, each band ordered by
     * next_poll_at ascending.
     *
     * @return Collection<int, FulfillmentJob>
     */
    private function selectDueJobs(CarbonImmutable $now): Collection
    {
        $base = FulfillmentJob::query()
            ->whereNotIn('status', [
                FulfillmentStatus::Completed->value,
                FulfillmentStatus::Cancelled->value,
                FulfillmentStatus::Failed->value,
            ])
            ->whereNotNull('supplier')
            ->whereNotNull('supplier_order_id')
            ->where('supplier_order_id', '!=', '')
            // null is the reconciler's "stop polling this" marker, so null is never due.
            ->whereNotNull('next_poll_at')
            ->where('next_poll_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('leased_until')->orWhere('leased_until', '<', $now);
            })
            // An admin can transition an order without touching the job row, so the
            // order's own status is checked too rather than trusting the job alone.
            ->whereHas('orderItem', function ($query): void {
                $query->whereHas('order', function ($query): void {
                    $query->whereNotIn('status', [
                        OrderStatus::Completed->value,
                        OrderStatus::Cancelled->value,
                        OrderStatus::Refunded->value,
                    ]);
                });
            })
            ->with(['orderItem.order', 'placements']);

        $cutoff = $now->copy()->subSeconds($this->attentionWindowSeconds());

        /** @var Collection<int, FulfillmentJob> $attention */
        $attention = (clone $base)
            ->where('last_viewed_at', '>=', $cutoff)
            ->orderBy('next_poll_at')
            ->limit(self::BATCH)
            ->get();

        /** @var Collection<int, FulfillmentJob> $background */
        $background = (clone $base)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_viewed_at')->orWhere('last_viewed_at', '<', $cutoff);
            })
            ->orderBy('next_poll_at')
            ->limit(self::BATCH)
            ->get();

        return $attention->concat($background);
    }

    private function claimLease(int $jobId, string $token): bool
    {
        $affected = FulfillmentJob::query()
            ->where('id', $jobId)
            ->where(function ($query): void {
                $query->whereNull('leased_until')->orWhere('leased_until', '<', now());
            })
            ->update([
                'lease_token' => $token,
                'leased_until' => now()->addSeconds($this->leaseSeconds()),
            ]);

        return $affected === 1;
    }

    private function releaseLease(int $jobId, string $token): void
    {
        FulfillmentJob::query()
            ->where('id', $jobId)
            ->where('lease_token', $token)
            ->update([
                'lease_token' => null,
                'leased_until' => null,
            ]);
    }

    private function deferForCircuit(FulfillmentJob $job, CarbonImmutable $availableAt): void
    {
        $jitter = random_int(0, intdiv($this->bandCadence($job), 4));

        FulfillmentJob::query()->where('id', $job->id)->update([
            'next_poll_at' => $availableAt->addSeconds($jitter),
        ]);
    }

    private function advancePoll(FulfillmentJob $job, ObservationOutcome $outcome): void
    {
        // The reconciler just wrote to this row, so re-read it before deciding the
        // next due time.
        $fresh = $job->refresh();

        // The reconciler owns terminal state: if it marked this job finished or
        // cleared its next_poll_at, writing a new due time would resurrect it.
        if ($this->isTerminal($fresh) || $fresh->next_poll_at === null) {
            return;
        }

        // The band is re-derived from the current row, because a customer may have
        // opened the page (stamping last_viewed_at) while this read was in flight.
        $cadence = $this->bandCadence($fresh);
        $jitter = random_int(0, intdiv($cadence, 4));
        $now = CarbonImmutable::now();

        if ($outcome->result === ObservationResult::Observed) {
            $fresh->poll_failure_count = 0;
            $fresh->next_poll_at = $now->addSeconds($cadence + $jitter);
        } elseif ($outcome->result === ObservationResult::Busy) {
            // Someone else is reading it right now: do not touch the counter, just
            // come back shortly.
            $fresh->next_poll_at = $now->addSeconds(5 + $jitter);
        } else {
            // Unreadable, Unavailable, NotConfigured: exponential backoff from the
            // band cadence, capped. Retry-After needs no special handling here because
            // SupplierGuard already turns it into an open circuit, handled above.
            $failureCount = $fresh->poll_failure_count + 1;
            $backoff = $this->backoffSeconds($cadence, $failureCount);
            $fresh->poll_failure_count = $failureCount;
            $fresh->next_poll_at = $now->addSeconds($backoff + $jitter);
        }

        $fresh->save();
    }

    /**
     * Exponential backoff from the band cadence, capped at the ceiling, computed
     * iteratively so a large failure count cannot overflow an int first.
     */
    private function backoffSeconds(int $cadence, int $failureCount): int
    {
        $ceiling = $this->backoffCeiling();
        $delay = $cadence;

        for ($i = 0; $i < $failureCount && $delay < $ceiling; $i++) {
            $delay = min($delay * 2, $ceiling);
        }

        return $delay;
    }

    private function bandCadence(FulfillmentJob $job): int
    {
        return $this->isAttention($job)
            ? $this->attentionCadenceSeconds()
            : $this->backgroundCadenceSeconds();
    }

    private function isAttention(FulfillmentJob $job): bool
    {
        $lastViewed = $job->last_viewed_at;

        if (! $lastViewed instanceof CarbonImmutable) {
            return false;
        }

        return $lastViewed->greaterThanOrEqualTo(CarbonImmutable::now()->subSeconds($this->attentionWindowSeconds()));
    }

    private function isTerminal(FulfillmentJob $job): bool
    {
        return in_array($job->status, [
            FulfillmentStatus::Completed,
            FulfillmentStatus::Cancelled,
            FulfillmentStatus::Failed,
        ], true);
    }

    private function deadlineReached(int $deadlineAt): bool
    {
        return time() >= $deadlineAt;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<float>  $latencies
     * @param  array<string, array{attempted: int, observed: int, failed: int, latencies: list<float>}>  $perSupplier
     */
    private function logSummary(
        array $counts,
        int $circuitSkipped,
        bool $deadlineHit,
        int $passes,
        int $attempted,
        CarbonImmutable $started,
        array $latencies,
        array $perSupplier,
    ): void {
        $suppliers = [];

        foreach ($perSupplier as $supplier => $bucket) {
            $suppliers[$supplier] = [
                'attempted' => $bucket['attempted'],
                'observed' => $bucket['observed'],
                'failed' => $bucket['failed'],
                'p95_ms' => $this->percentile($bucket['latencies'], 95),
            ];
        }

        Log::info('Fulfillment poll completed.', [
            'passes' => $passes,
            'attempted' => $attempted,
            'observed' => $counts[ObservationResult::Observed->value] ?? 0,
            'unreadable' => $counts[ObservationResult::Unreadable->value] ?? 0,
            'unavailable' => $counts[ObservationResult::Unavailable->value] ?? 0,
            'not_configured' => $counts[ObservationResult::NotConfigured->value] ?? 0,
            'busy' => $counts[ObservationResult::Busy->value] ?? 0,
            'circuit_skipped' => $circuitSkipped,
            'deadline_hit' => $deadlineHit,
            // Carbon returns a signed difference in the order the two instants were
            // given, so without the absolute flag this logs the run length with a
            // minus in front - and the number the capacity decision rests on is the
            // one nobody would think to sanity-check.
            'duration_ms' => (int) round(CarbonImmutable::now()->diffInMilliseconds($started, true)),
            'latency_p50_ms' => $this->percentile($latencies, 50),
            'latency_p95_ms' => $this->percentile($latencies, 95),
            'per_supplier' => $suppliers,
        ]);
    }

    /**
     * Nearest-rank percentile over a tick's latency samples; a single sample is
     * reported as-is, no samples as null.
     *
     * @param  list<float>  $samples
     */
    private function percentile(array $samples, int $percentile): ?float
    {
        if ($samples === []) {
            return null;
        }

        if (count($samples) < 2) {
            return $samples[0];
        }

        sort($samples);

        $index = (int) ceil($percentile / 100 * count($samples)) - 1;

        return (float) $samples[max(0, $index)];
    }

    private function attentionWindowSeconds(): int
    {
        return max(1, (int) config('services.suppliers.poll.attention_window_seconds', 180));
    }

    private function attentionCadenceSeconds(): int
    {
        return max(1, (int) config('services.suppliers.poll.attention_cadence_seconds', 25));
    }

    private function backgroundCadenceSeconds(): int
    {
        return max(1, (int) config('services.suppliers.poll.background_cadence_seconds', 180));
    }

    private function backoffCeiling(): int
    {
        return max(1, (int) config('services.suppliers.poll.backoff_ceiling_seconds', 600));
    }

    private function leaseSeconds(): int
    {
        return max(1, (int) config('services.suppliers.poll.lease_seconds', 30));
    }

    private function deadlineSeconds(): int
    {
        $deadline = $this->option('deadline');
        $deadline = is_numeric($deadline) ? (int) $deadline : (int) config('services.suppliers.poll.deadline_seconds', 50);

        return max(5, min(55, $deadline));
    }

    private function limit(): int
    {
        $limit = $this->option('limit');

        return is_numeric($limit) ? max(0, (int) $limit) : 0;
    }
}
