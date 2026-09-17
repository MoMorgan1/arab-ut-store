<?php

namespace App\Actions\Fulfillment;

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
     */
    public function execute(
        FulfillmentJob $job,
        ?CarbonImmutable $previousObservedAt,
        ?string $previousState,
    ): void {
        $observedAt = $job->observed_at;

        // A job's first observation has nothing to measure from. Recording a
        // zero for it would be the single easiest way to make every
        // distribution read lower than the truth, and the row would be
        // indistinguishable from a genuine back-to-back pair afterwards.
        if (! $previousObservedAt instanceof CarbonImmutable || ! $observedAt instanceof CarbonImmutable) {
            return;
        }

        // An observation older than the one stored is discarded by the caller
        // before it gets here, so this is defence rather than a branch anyone
        // reaches. It matters because the column is unsigned: an absolute
        // difference would silently turn a clock that went backwards into a
        // plausible-looking gap.
        if ($observedAt->lessThan($previousObservedAt)) {
            return;
        }

        FulfillmentObservationGap::query()->create([
            'fulfillment_job_id' => $job->id,
            'delivery_phase' => $job->delivery_phase,
            'supplier' => $job->supplier,
            'gap_seconds' => (int) $previousObservedAt->diffInSeconds($observedAt, true),
            'state_changed' => $job->observed_state !== $previousState,
            'observed_at' => $observedAt,
        ]);
    }
}
