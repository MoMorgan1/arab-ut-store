<?php

namespace App\Console\Commands;

use App\Enums\DeliveryPhase;
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
 * Two scopes per phase, because they answer two different questions and the
 * cadence table needs the second one. "every reading" is the interval between
 * supplier reads, which is largely our own poll cadence read back to us.
 * "readings that moved" is the interval a job actually went before its state
 * changed - which is what "an open job whose newest observation is older than
 * the cadence expected for its phase" is really asking about.
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

        foreach ($this->phases() as $label => $phase) {
            foreach ([false, true] as $movedOnly) {
                $row = $this->describe($label, $phase, $movedOnly, $since, $supplier);

                if ($row !== null) {
                    $rows[] = $row;
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
            ['phase', 'scope', 'samples', 'p50', 'p90', 'p95', 'p99', 'max'],
            $rows,
        );

        $this->line('');
        $this->components->info(
            'A cadence threshold belongs above the "readings that moved" p99 of its phase, not at its p50: '
            .'the table is here to say how long normal is, and the alarm fires past normal.'
        );

        $configured = config('services.suppliers.alarm.stalled_after_minutes');
        $unset = ! is_array($configured) || array_filter($configured, static fn ($value): bool => is_numeric($value) && (int) $value > 0) === [];

        if ($unset) {
            $this->components->warn(
                'services.suppliers.alarm.stalled_after_minutes is still entirely unset, so no stall alarm is armed.'
            );
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
     * @return array{0: string, 1: string, 2: int, 3: string, 4: string, 5: string, 6: string, 7: string}|null
     */
    private function describe(string $label, ?DeliveryPhase $phase, bool $movedOnly, mixed $since, ?string $supplier): ?array
    {
        $samples = $this->scope($phase, $movedOnly ? true : null, $since, $supplier)->count();

        if ($samples === 0) {
            return null;
        }

        $percentile = fn (int $p): string => $this->format(
            $this->percentile($this->scope($phase, $movedOnly ? true : null, $since, $supplier), $p, $samples),
        );

        return [
            $label,
            $movedOnly ? 'readings that moved' : 'every reading',
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
    private function scope(?DeliveryPhase $phase, ?bool $stateChanged, mixed $since, ?string $supplier): Builder
    {
        return $this->window($since, $supplier)
            ->when(
                $phase instanceof DeliveryPhase,
                fn (Builder $query) => $query->where('delivery_phase', $phase?->value),
                fn (Builder $query) => $query->whereNull('delivery_phase'),
            )
            ->when($stateChanged !== null, fn (Builder $query) => $query->where('state_changed', $stateChanged));
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
