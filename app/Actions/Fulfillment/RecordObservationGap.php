<?php

namespace App\Actions\Fulfillment;

use App\Enums\PollBand;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentObservationGap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the interval between two landed observations of one job.
 *
 * Owner decision, 2026-09-17: the numbers behind the phase cadence table are
 * to be queryable, not logged. D3a's instrumentation is a `Log::info` line that
 * production's `warning` level discards, and even with the level raised a log
 * is not a thing anyone can take a percentile of in a month's time.
 *
 * Two halves, and the split is the point. {@see self::measure()} runs inside
 * `ApplySupplierObservation`'s transaction because that is the only place the
 * previous reading still exists; it touches no database.
 * {@see self::record()} runs after that transaction has committed, and
 * swallows its own failures.
 *
 * That ordering is not tidiness. The transaction holds `lockForUpdate` on the
 * order, every one of its items and the job, and it carries the item and order
 * status moves, the cashback accrual and the challenge-solve enqueue. An
 * INSERT failing inside it - a deadlock under contention, a full disk on
 * shared hosting - would roll all of that back and retry it, which would mean
 * a customer's order did not advance because a measurement of that order
 * could not be written down. A measurement must never be load-bearing for the
 * write path it measures.
 *
 * Never called on a failed read, and never on one that could not be read. A
 * read that did not land never reaches `ApplySupplierObservation` at all, and
 * an unsupported answer - a challenge id collision, an empty response, a
 * status the translator does not recognise - carries no news and so has no
 * interval to it.
 */
final class RecordObservationGap
{
    /**
     * The row this observation earned, or null when it earned none.
     *
     * Pure: it reads the job in memory and builds an unsaved model. Nothing
     * here may touch the database, because the transaction it runs inside is
     * holding locks on a customer's order.
     *
     * @param  FulfillmentJob  $job  Carrying the new observation already, so the
     *                               phase recorded is the phase this reading
     *                               put the job in.
     * @param  ObservationSnapshot  $before  What the job said a moment ago.
     * @param  Supplier|null  $observedBy  The supplier this reading was
     *                                     actually taken from, when the caller
     *                                     knows it.
     */
    public function measure(FulfillmentJob $job, ObservationSnapshot $before, ?Supplier $observedBy = null): ?FulfillmentObservationGap
    {
        $observedAt = $job->observed_at;
        $previousObservedAt = $before->observedAt;

        // A job's first observation has nothing to measure from. Recording a
        // zero for it would be the single easiest way to make every
        // distribution read lower than the truth, and the row would be
        // indistinguishable from a genuine back-to-back pair afterwards.
        if (! $previousObservedAt instanceof CarbonImmutable || ! $observedAt instanceof CarbonImmutable) {
            return null;
        }

        // Equal counts as no gap, not as a gap of zero, and this is a real
        // branch rather than defence. The caller discards an observation
        // strictly older than the stored one, so a reading replayed after its
        // own commit - the same payload with the same `fetchedAt`, which is
        // exactly what a retried delivery is - passes that guard and arrives
        // here carrying an interval of nothing. One such row per replay drags
        // every percentile down, and the table exists to be taken percentiles
        // of. The strictly-older case is folded in for the unsigned column's
        // sake: an absolute difference would turn a clock that went backwards
        // into a plausible-looking gap.
        if ($observedAt->lessThanOrEqualTo($previousObservedAt)) {
            return null;
        }

        return new FulfillmentObservationGap([
            'fulfillment_job_id' => $job->id,
            'delivery_phase' => $job->delivery_phase,
            // The supplier that answered, which is not always the one on the
            // job. `RecordSupplierPlacement` keeps the first placement's
            // supplier on the job row on purpose, while a challenge read goes
            // to the challenge placement's supplier - so a job that bought
            // coins from UTT and solves challenges at FFT would file every FFT
            // reading under UTT, and the report's supplier filter would lie.
            'supplier' => $observedBy ?? $job->supplier,
            'band' => PollBand::for($job->last_viewed_at),
            'gap_seconds' => (int) $previousObservedAt->diffInSeconds($observedAt, true),
            'moved' => $this->broughtNews($job, $before),
            'poll_failure_count' => (int) $job->poll_failure_count,
            'coins_delivered' => $job->coins_delivered,
            'squads_done' => $job->squads_done,
            'solves_done' => $job->solves_done,
            'observed_at' => $observedAt,
        ]);
    }

    /**
     * Writes the measurement, and gives up quietly if it cannot.
     *
     * Every `Throwable` is caught, which is the one place in this codebase
     * that is right rather than lazy. The house rule against catching
     * `Throwable` exists so an outside failure does not become a 500; the rule
     * being served here is the stronger one above it - a measurement may not
     * change the outcome of the thing it measures. The ways an INSERT can fail
     * are an open set and the correct answer to every one of them is the same:
     * drop the sample, say so at error level because a failing write here is
     * ours to fix, and carry on.
     *
     * Without the catch the throw would not roll anything back - the write has
     * already committed - but it would escape into the poller, which turns any
     * `Throwable` into a failed read. A telemetry table that could not be
     * written would then inflate `poll_failure_count` and walk the job into
     * the silence alarm, which is the same defect wearing a different hat.
     */
    public function record(?FulfillmentObservationGap $gap): void
    {
        if (! $gap instanceof FulfillmentObservationGap) {
            return;
        }

        try {
            $gap->save();
        } catch (Throwable $exception) {
            Log::error('Could not record a fulfillment observation gap for job {job_id}', [
                'job_id' => $gap->fulfillment_job_id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Whether this reading said anything the one before it did not.
     *
     * The state string is the obvious half and not the whole of it: a shipment
     * that delivered another fifty thousand coins while the supplier went on
     * calling it "started" told us something, and string inequality alone
     * would file that reading as silence. Counters only count upward here -
     * a counter that went backwards is a supplier contradicting itself, not
     * progress - and a counter arriving where there was none is news.
     */
    private function broughtNews(FulfillmentJob $job, ObservationSnapshot $before): bool
    {
        if ($job->observed_state !== $before->observedState) {
            return true;
        }

        return $this->advanced($before->coinsDelivered, $job->coins_delivered)
            || $this->advanced($before->squadsDone, $job->squads_done)
            || $this->advanced($before->solvesDone, $job->solves_done);
    }

    private function advanced(?int $before, ?int $after): bool
    {
        if ($after === null) {
            return false;
        }

        return $before === null || $after > $before;
    }
}
