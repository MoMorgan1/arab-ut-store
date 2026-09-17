<?php

namespace App\Actions\Fulfillment;

use App\Actions\Pricing\PrunePricingHistory;
use App\Models\FulfillmentObservationGap;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deletes the observation gaps that have aged out of the retention window.
 *
 * The poller writes one row per landed observation for the life of an order,
 * so this table is the only one in the fulfillment set that grows with time
 * rather than with sales - and it grows per job, so the bill is the aggregate
 * and not the one job it is easy to reason about. A job nobody is watching
 * writes about 480 rows a day on the three-minute cadence; a job whose order
 * page is open writes about 3,456 on the twenty-five-second one, which at
 * launch is most of them. A thousand concurrent background jobs is therefore
 * around 6.7 million rows inside the fortnight, and an attention-heavy day is
 * several times that.
 *
 * Two consequences this has to hold up under. The window only holds if a
 * single nightly run clears a whole day's writes - roughly 480,000 rows at
 * that scale - so the loop below is deliberately unbounded: it runs until
 * nothing older than the cutoff is left, because a prune that gives up early
 * leaves a table that grows forever by a little every night. And the chunk is
 * a thousand rather than a handful, because five hundred chunks of a hundred
 * is five hundred transactions to do one night's work.
 *
 * Nothing depends on an old row surviving. The table is a sample to take
 * percentiles from, and a percentile taken over the last fortnight describes
 * the suppliers we are dealing with now; one taken over a year describes a
 * season that has ended. Deleted in chunks for the same reason
 * {@see PrunePricingHistory} is: a single DELETE over several hundred thousand
 * rows is how a nightly job starts timing out on shared hosting.
 */
final class PruneObservationGaps
{
    private const CHUNK_SIZE = 1000;

    public function execute(): int
    {
        $retentionDays = config('services.suppliers.poll.gap_retention_days');

        // Loud rather than silent: a missing window here would otherwise read
        // as "keep nothing" or "keep everything" depending on how it was cast,
        // and both are wrong in a way nobody would notice for months.
        if (! is_int($retentionDays) || $retentionDays < 1) {
            throw new RuntimeException('The fulfillment observation gap retention window is unavailable.');
        }

        return $this->deleteInChunks(now()->subDays($retentionDays));
    }

    private function deleteInChunks(CarbonInterface $cutoff): int
    {
        $deleted = 0;

        do {
            $deletedChunk = DB::transaction(function () use ($cutoff): int {
                $ids = FulfillmentObservationGap::query()
                    ->where('observed_at', '<', $cutoff)
                    ->orderBy('id')
                    ->limit(self::CHUNK_SIZE)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    return 0;
                }

                return FulfillmentObservationGap::query()->whereIn('id', $ids)->delete();
            }, attempts: 3);

            $deleted += $deletedChunk;
        } while ($deletedChunk === self::CHUNK_SIZE);

        return $deleted;
    }
}
