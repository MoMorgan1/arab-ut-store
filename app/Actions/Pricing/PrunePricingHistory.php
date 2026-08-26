<?php

namespace App\Actions\Pricing;

use App\Enums\ServiceType;
use App\Models\PriceRule;
use App\Models\PriceRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Delete the pricing runs and superseded rules that have aged out.
 *
 * A run lands every hour and each one stores its whole snapshot, then writes the
 * same three configurations again as rules. Nothing ever removed either: a
 * superseded rule was only flipped inactive. Left alone the two tables grow
 * forever, and every byte of that growth rides into every backup and replica.
 *
 * Replay protection does not depend on these rows surviving. A pricing request
 * carries a signed timestamp and VerifyN8nPricingSignature refuses anything more
 * than five minutes old, so a request old enough to be pruned here can no longer
 * reach the run and event checks at all.
 */
final class PrunePricingHistory
{
    private const CHUNK_SIZE = 500;

    /** @return array{runs: int, rules: int} */
    public function execute(): array
    {
        $retentionDays = config('coins.pricing.retention_days');

        if (! is_int($retentionDays) || $retentionDays < 1) {
            throw new RuntimeException('The Coins pricing retention window is unavailable.');
        }

        // Measured from when a row last changed, not from when it was written.
        // A run that supersedes a rule touches it, so a rule that served prices
        // through a long stall gets its full window from the day it stopped
        // serving - not from a creation date that may be months earlier.
        $cutoff = now()->subDays($retentionDays);

        return [
            'runs' => $this->pruneRuns($cutoff),
            'rules' => $this->pruneRules($cutoff),
        ];
    }

    /**
     * The newest run, and the newest applied one, are kept whatever their age.
     *
     * They are the answer to "where did the prices currently on the storefront
     * come from", and if pricing ever stalls for longer than the window - n8n
     * down, credentials expired - age is exactly the wrong reason to discard the
     * last thing that worked.
     */
    private function pruneRuns(CarbonInterface $cutoff): int
    {
        $keep = array_values(array_filter([
            PriceRun::query()->max('id'),
            PriceRun::query()->where('status', 'applied')->max('id'),
        ]));

        return $this->deleteInChunks(
            fn () => PriceRun::query()
                ->where('updated_at', '<=', $cutoff)
                ->whereNotIn('id', $keep),
        );
    }

    /**
     * Only the global Coins rules a pricing run superseded.
     *
     * An active rule is what the storefront prices from and stays until a run
     * replaces it, however long that takes. The scope matters as much: price_rules
     * is a shared table, and "inactive" is not the same claim as "superseded" -
     * a variant-scoped rule an admin paused by hand would otherwise be deleted
     * out from under them a month later.
     */
    private function pruneRules(CarbonInterface $cutoff): int
    {
        return $this->deleteInChunks(
            fn () => PriceRule::query()
                ->where('service_type', ServiceType::Coins->value)
                ->whereNull('product_variant_id')
                ->whereNull('platform')
                ->where('is_active', false)
                ->where('updated_at', '<=', $cutoff),
        );
    }

    /** @param  callable(): Builder<covariant \Illuminate\Database\Eloquent\Model>  $eligible */
    private function deleteInChunks(callable $eligible): int
    {
        $deleted = 0;

        do {
            $deletedChunk = DB::transaction(function () use ($eligible): int {
                $ids = $eligible()
                    ->orderBy('id')
                    ->limit(self::CHUNK_SIZE)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    return 0;
                }

                return $eligible()->whereIn('id', $ids)->delete();
            }, attempts: 3);

            $deleted += $deletedChunk;
        } while ($deletedChunk === self::CHUNK_SIZE);

        return $deleted;
    }
}
