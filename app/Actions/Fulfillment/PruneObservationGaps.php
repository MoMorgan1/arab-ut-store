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
 * rather than with sales. Left alone it would outgrow everything around it,
 * and every byte of that growth rides into every backup.
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
    private const CHUNK_SIZE = 500;

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
