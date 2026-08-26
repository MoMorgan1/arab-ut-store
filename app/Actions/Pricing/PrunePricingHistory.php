<?php

namespace App\Actions\Pricing;

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
                ->where('created_at', '<=', $cutoff)
                ->whereNotIn('id', $keep),
        );
    }

    /**
     * Only rules already taken out of service. An active rule is what the
     * storefront prices from, and it stays until a run replaces it - however
     * long that takes.
     */
    private function pruneRules(CarbonInterface $cutoff): int
    {
        return $this->deleteInChunks(
            fn () => PriceRule::query()
                ->where('is_active', false)
                ->where('created_at', '<=', $cutoff),
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
