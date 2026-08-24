<?php

namespace App\Imports\Salla;

use App\Models\ExchangeRate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Convert an imported order's money into SAR.
 *
 * Salla orders arrive in fourteen currencies, and every place that reads
 * lifetime spend or loyalty tiers filters on SAR - so an unconverted order
 * contributes nothing and a customer whose history is mostly AED lands in the
 * bottom tier. Converting at import is what makes those totals whole.
 *
 * Rates come from the `exchange_rates` table, quoted as units of the foreign
 * currency per one SAR. Everything the table covers today is either SAR-pegged
 * or dollar-pegged (AED, KWD, BHD, OMR, QAR) or moves narrowly against a
 * dollar-pegged riyal (USD, EUR, GBP), so applying a current rate to a
 * historical order is accurate rather than merely convenient. A currency with
 * no rate is deliberately NOT guessed at: the order keeps its own currency and
 * is reported, because inventing a number for a free-floating currency that has
 * moved by half since 2024 would be worse than leaving it visible.
 *
 * The arithmetic is bcmath on integer strings. Money never touches a float.
 */
final class CurrencyConverter
{
    /** @var array<string, array{rate: numeric-string, fetchedAt: ?string}>|null */
    private ?array $rates = null;

    /**
     * @return array{halalah: int, converted: bool, rate: ?numeric-string, fetchedAt: ?string}
     */
    public function toSar(int $amountMinor, string $currency): array
    {
        $currency = strtoupper(trim($currency));

        if ($currency === '' || $currency === 'SAR') {
            return ['halalah' => $amountMinor, 'converted' => false, 'rate' => null, 'fetchedAt' => null];
        }

        $rate = $this->rates()[$currency] ?? null;

        if ($rate === null || bccomp($rate['rate'], '0', 8) !== 1) {
            return ['halalah' => $amountMinor, 'converted' => false, 'rate' => null, 'fetchedAt' => null];
        }

        // rate is "foreign per 1 SAR", so SAR = foreign / rate. Divide at a
        // scale of 2 and round the half up by hand - bcdiv truncates.
        $scaled = bcdiv((string) $amountMinor, $rate['rate'], 2);
        $rounded = bcadd($scaled, '0.5', 0);

        return [
            'halalah' => (int) $rounded,
            'converted' => true,
            'rate' => $rate['rate'],
            'fetchedAt' => $rate['fetchedAt'],
        ];
    }

    public function hasRate(string $currency): bool
    {
        $currency = strtoupper(trim($currency));

        return $currency === 'SAR' || isset($this->rates()[$currency]);
    }

    /** @return array<string, array{rate: numeric-string, fetchedAt: ?string}> */
    private function rates(): array
    {
        if ($this->rates !== null) {
            return $this->rates;
        }

        /** @var Collection<int, ExchangeRate> $rows */
        $rows = ExchangeRate::query()
            ->where('base_currency', 'SAR')
            ->get();

        $rates = [];

        foreach ($rows as $row) {
            $raw = (string) $row->getRawOriginal('rate');

            if (! is_numeric($raw)) {
                continue;
            }

            $fetchedAt = $row->getAttribute('fetched_at');

            $rates[strtoupper((string) $row->quote_currency)] = [
                'rate' => $raw,
                'fetchedAt' => $fetchedAt instanceof CarbonInterface ? $fetchedAt->toIso8601String() : null,
            ];
        }

        return $this->rates = $rates;
    }
}
