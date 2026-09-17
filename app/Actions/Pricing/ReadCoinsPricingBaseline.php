<?php

namespace App\Actions\Pricing;

use App\Models\PriceRun;
use DomainException;

/**
 * What the last applied pricing run published, in the shape the workflow
 * carries forward.
 *
 * The workflow kept this in n8n's own workflow memory, which is only ever
 * handed to a SCHEDULED execution: n8n starts a manual one with an empty
 * object. So pressing Execute to check your work reported "no published rates
 * exist to carry forward" every time, on a workflow that was perfectly
 * healthy, and the only way to see a run was to wait for the hour. The store
 * is the one place that actually knows what was last published, so the
 * workflow asks it instead of remembering.
 *
 * It is the same three fields the memory holds, deliberately: a run reads this
 * only when the memory is empty, and the two have to be interchangeable.
 */
final readonly class ReadCoinsPricingBaseline
{
    /**
     * @return array{
     *   schemaVersion: 1,
     *   runId: string,
     *   pricingVersion: int,
     *   appliedAt: string,
     *   rates: array{console_normal: int, console_fast: list<int>, pc: list<int>},
     *   cyclePSUsdPerM: float|null,
     *   cyclePCUsdPerM: float|null
     * }
     *
     * @throws DomainException when no applied run carries a readable set of rates
     */
    public function execute(): array
    {
        $run = PriceRun::query()
            ->where('status', 'applied')
            ->orderByDesc('id')
            ->first();

        if (! $run instanceof PriceRun) {
            throw new DomainException('No applied Coins pricing run exists.');
        }

        $payload = is_array($run->payload) ? $run->payload : [];
        $rules = is_array($payload['rules'] ?? null) ? $payload['rules'] : [];
        $observations = is_array($payload['observations'] ?? null) ? $payload['observations'] : [];

        return [
            'schemaVersion' => 1,
            'runId' => (string) $run->run_id,
            'pricingVersion' => (int) $run->pricing_version,
            'appliedAt' => $this->appliedAt($run),
            'rates' => [
                'console_normal' => $this->flatRate($rules, 'console_normal'),
                'console_fast' => $this->tierRates($rules, 'console_fast'),
                'pc' => $this->tierRates($rules, 'pc'),
            ],
            'cyclePSUsdPerM' => $this->cost($observations, 'cyclePSUsdPerM'),
            'cyclePCUsdPerM' => $this->cost($observations, 'cyclePCUsdPerM'),
        ];
    }

    /**
     * When the run finished. `ApplyCoinsPricingRun` stamps it inside the same
     * transaction that writes the row, so an applied run always has one.
     */
    private function appliedAt(PriceRun $run): string
    {
        return $run->completed_at?->toIso8601String() ?? '';
    }

    /** @param array<string, mixed> $rules */
    private function flatRate(array $rules, string $group): int
    {
        $rate = is_array($rules[$group] ?? null)
            ? ($rules[$group]['flat_rate_halalah_per_million'] ?? null)
            : null;

        if (! is_int($rate) || $rate <= 0) {
            throw new DomainException("The applied run carries no {$group} rate.");
        }

        return $rate;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return list<int>
     */
    private function tierRates(array $rules, string $group): array
    {
        $rates = is_array($rules[$group] ?? null)
            ? ($rules[$group]['tier_rates_halalah_per_million'] ?? null)
            : null;

        if (! is_array($rates) || ! array_is_list($rates) || $rates === []) {
            throw new DomainException("The applied run carries no {$group} rates.");
        }

        $clean = [];

        foreach ($rates as $rate) {
            if (! is_int($rate) || $rate <= 0) {
                throw new DomainException("The applied run carries a malformed {$group} rate.");
            }

            $clean[] = $rate;
        }

        return $clean;
    }

    /** @param array<string, mixed> $observations */
    private function cost(array $observations, string $key): ?float
    {
        $value = $observations[$key] ?? null;

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}
