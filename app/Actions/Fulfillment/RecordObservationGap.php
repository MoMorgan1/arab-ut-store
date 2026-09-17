<?php

namespace App\Actions\Fulfillment;

use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentObservationGap;
use Carbon\CarbonImmutable;

/**
 * Keeps the interval between two landed observations of one job.
 *
 * Owner decision, 2026-09-17: the numbers behind the phase cadence table are
 * to be queryable, not logged. D3a's instrumentation is a `Log::info` line that
 * production's `warning` level discards, and even with the level raised a log
 * is not a thing anyone can take a percentile of in a month's time.
 *
 * The interval is computable exactly once, and this is the moment:
 * `ApplySupplierObservation` is about to overwrite `observed_at`, and the
 * value being overwritten is the only record that the previous reading ever
 * happened. Called from inside that action's transaction, so a gap row and the
 * job state it describes commit together or not at all.
 *
 * Never called on a failed read. A read that did not land never reaches
 * `ApplySupplierObservation` at all - the supplier exceptions are turned into
 * outcomes one layer up - so a supplier being down leaves no trace here. That
 * is on purpose: a gap is time between two readings, and there is no second
 * reading to measure to.
 */
final class RecordObservationGap
{
    /**
     * @param  FulfillmentJob  $job  Carrying the new observation already, so
     *                               the phase recorded is the phase this
     *                               reading put the job in.
     * @param  CarbonImmutable|null  $previousObservedAt  Null on a job's first
     *                                                    observation.
     * @param  Supplier|null  $observedBy  The supplier this reading was
     *                                     actually taken from, when the caller
     *                                     knows it.
     */
    public function execute(
        FulfillmentJob $job,
        ?CarbonImmutable $previousObservedAt,
        ?string $previousState,
        ?Supplier $observedBy = null,
    ): void {
        $observedAt = $job->observed_at;

        // A job's first observation has nothing to measure from. Recording a
        // zero for it would be the single easiest way to make every
        // distribution read lower than the truth, and the row would be
        // indistinguishable from a genuine back-to-back pair afterwards.
        if (! $previousObservedAt instanceof CarbonImmutable || ! $observedAt instanceof CarbonImmutable) {
            return;
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
            return;
        }

        FulfillmentObservationGap::query()->create([
            'fulfillment_job_id' => $job->id,
            'delivery_phase' => $job->delivery_phase,
            // The supplier that answered, which is not always the one on the
            // job. `RecordSupplierPlacement` keeps the first placement's
            // supplier on the job row on purpose, while a challenge read goes
            // to the challenge placement's supplier - so a job that bought
            // coins from UTT and solves challenges at FFT would file every FFT
            // reading under UTT, and the report's supplier filter would lie.
            'supplier' => $observedBy ?? $job->supplier,
            'gap_seconds' => (int) $previousObservedAt->diffInSeconds($observedAt, true),
            'state_changed' => $job->observed_state !== $previousState,
            'observed_at' => $observedAt,
        ]);
    }
}
