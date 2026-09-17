<?php

namespace App\Console\Commands;

use App\Enums\DeliveryPhase;
use App\Enums\PollBand;
use App\Models\FulfillmentObservationGap;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Prints the per-phase distribution of measured observation gaps.
 *
 * This is the command the phase cadence table is meant to be written from, and
 * the reason it is a command rather than a note in a document: reading the
 * answer in a month should be one line, not a research project that starts by
 * rediscovering which table holds what.
 *
 * Read-only. It runs nothing but SELECTs, so it is safe against production:
 *
 *     ssh arabut-prod "cd /home/u372356793/domains/store.arab-ut.com/current \
 *         && php artisan fulfillment:observation-gaps --days=14"
 *
 * Both scopes measure the same thing - the interval between two consecutive
 * readings - and neither measures how long a job sat in a state. "the reading
 * that brought news" is that same interval, restricted to the readings where
 * the state came back different, so it says what the poll interval happened to
 * be at the moment news arrived and nothing at all about how long the news took
 * to arrive. A job polled every three minutes that finally moves after two
 * hours contributes a three-minute row, not a two-hour one.
 *
 * Read it that way and it is still worth having: it is the sample of intervals
 * during which the store was demonstrably being told things, so a threshold
 * above it cannot be firing on a job a supplier is actively answering about.
 * Measuring time-in-state needs a different table - the transitions, not the
 * gaps between reads - and this is not it.
 */
final class ShowObservationGaps extends Command
{
    protected $signature = 'fulfillment:observation-gaps {--days=14} {--supplier=}';

    protected $description = 'Print the per-phase distribution of measured supplier observation gaps';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $supplier = $this->option('supplier');
        $supplier = is_string($supplier) && $supplier !== '' ? $supplier : null;
        $since = now()->subDays($days);

        $total = $this->window($since, $supplier)->count();

        if ($total === 0) {
            $this->components->warn(sprintf(
                'No observation gaps recorded in the last %d day(s)%s. The cadence table cannot be written from this.',
                $days,
                $supplier === null ? '' : " for supplier {$supplier}",
            ));

            return self::SUCCESS;
        }

        $rows = [];

        // Phase and band both, because a threshold is per band and a
        // distribution that averaged the two would describe neither.
        foreach ($this->phases() as $label => $phase) {
            foreach (PollBand::cases() as $band) {
                foreach ([false, true] as $movedOnly) {
                    $row = $this->describe($label, $phase, $band, $movedOnly, $since, $supplier);

                    if ($row !== null) {
                        $rows[] = $row;
                    }
                }
            }
        }

        $this->components->info(sprintf(
            '%d gap(s) over the last %d day(s)%s. Seconds.',
            $total,
            $days,
            $supplier === null ? '' : ", supplier {$supplier}",
        ));

        $this->table(
            ['phase', 'band', 'scope', 'samples', 'p50', 'p90', 'p95', 'p99', 'max'],
            $rows,
        );

        $this->line('');
        $this->components->info(
            'Set a phase threshold above its "every reading" p99, with room over it. That column is how long '
            .'a healthy job goes between readings, so a threshold under it fires on jobs the supplier is '
            .'answering about. Neither column measures how long a job sat in one state.'
        );

        $byPhase = config('services.suppliers.alarm.stalled_after_minutes');

        if (! is_array($byPhase) || $byPhase === []) {
            $fallback = config('services.suppliers.alarm.stalled_fallback_minutes');

            $this->components->warn(sprintf(
                'No phase has a measured threshold yet, so every phase is on the %s-minute fallback. '
                .'A measured one goes in services.suppliers.alarm.stalled_after_minutes.',
                is_numeric($fallback) && (int) $fallback > 0 ? (string) (int) $fallback : 'unset',
            ));
        }

        return self::SUCCESS;
    }

    /** @return array<string, DeliveryPhase|null> */
    private function phases(): array
    {
        $phases = [];

        foreach (DeliveryPhase::cases() as $phase) {
            $phases[$phase->value] = $phase;
        }

        // The cadence table's own key for a job with no phase.
        $phases['none'] = null;

        return $phases;
    }

    /**
     * @return list<string|int>|null
     */
    private function describe(string $label, ?DeliveryPhase $phase, PollBand $band, bool $movedOnly, mixed $since, ?string $supplier): ?array
    {
        $scope = fn () => $this->scope($phase, $band, $movedOnly ? true : null, $since, $supplier);
        $samples = $scope()->count();

        if ($samples === 0) {
            return null;
        }

        $percentile = fn (int $p): string => $this->format($this->percentile($scope(), $p, $samples));

        return [
            $label,
            $band->value,
            $movedOnly ? 'the reading that brought news' : 'every reading',
            $samples,
            $percentile(50),
            $percentile(90),
            $percentile(95),
            $percentile(99),
            $percentile(100),
        ];
    }

    /**
     * Everything in the window, whatever its phase.
     *
     * Separate from {@see self::scope()} because a null phase means two
     * different things - "every phase" to the total, "the phaseless jobs" to a
     * row of the table - and one method answering both would have quietly
     * reported the phaseless count as the grand total.
     *
     * @return Builder<FulfillmentObservationGap>
     */
    private function window(mixed $since, ?string $supplier): Builder
    {
        return FulfillmentObservationGap::query()
            ->where('observed_at', '>=', $since)
            ->when($supplier !== null, fn (Builder $query) => $query->where('supplier', $supplier));
    }

    /** @return Builder<FulfillmentObservationGap> */
    private function scope(?DeliveryPhase $phase, PollBand $band, ?bool $moved, mixed $since, ?string $supplier): Builder
    {
        return $this->window($since, $supplier)
            ->when(
                $phase instanceof DeliveryPhase,
                fn (Builder $query) => $query->where('delivery_phase', $phase?->value),
                fn (Builder $query) => $query->whereNull('delivery_phase'),
            )
            ->where('band', $band->value)
            ->when($moved !== null, fn (Builder $query) => $query->where('moved', $moved));
    }

    /**
     * Nearest rank, computed by the database rather than in memory.
     *
     * One OFFSET into an ordered column reads the sample this percentile names
     * without pulling the other several hundred thousand into PHP, and the
     * definition is the one {@see PollFulfillmentJobs::percentile()} already
     * uses, so two places in the codebase do not report a p95 that means two
     * different things.
     *
     * @param  Builder<FulfillmentObservationGap>  $scope
     */
    private function percentile(Builder $scope, int $percentile, int $samples): ?int
    {
        if ($samples === 0) {
            return null;
        }

        $offset = max(0, (int) ceil($percentile / 100 * $samples) - 1);

        $value = $scope->orderBy('gap_seconds')->offset($offset)->limit(1)->value('gap_seconds');

        return $value === null ? null : (int) $value;
    }

    private function format(?int $seconds): string
    {
        if ($seconds === null) {
            return '-';
        }

        if ($seconds < 120) {
            return "{$seconds}s";
        }

        return $seconds.'s ('.round($seconds / 60, 1).'m)';
    }
}
