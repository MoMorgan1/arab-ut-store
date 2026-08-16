<?php

namespace App\ValueObjects\Pricing;

use DomainException;

final readonly class FutChampionsPricing
{
    private const RANKS = [1, 2, 3, 4, 5, 6];

    /** @param array<int, int> $rankPricesHalalah */
    private function __construct(
        private array $rankPricesHalalah,
        private int $urgentSurchargeHalalah,
    ) {}

    /** @param array<string, mixed> $configuration */
    public static function fromConfiguration(array $configuration): self
    {
        self::requireExactKeys($configuration, ['ranks', 'urgent_surcharge_halalah']);

        $configuredRanks = $configuration['ranks'] ?? null;

        if (! is_array($configuredRanks)) {
            throw new DomainException('The FUT Champions rank prices have an invalid shape.');
        }

        $rankPrices = [];

        foreach (self::RANKS as $rank) {
            if (! array_key_exists($rank, $configuredRanks)) {
                throw new DomainException('Every FUT Champions rank price must be declared.');
            }

            $rankPrices[$rank] = self::positiveInteger(
                $configuredRanks[$rank],
                'rank price',
            );
        }

        $actualRanks = array_keys($configuredRanks);
        sort($actualRanks, SORT_NUMERIC);

        if ($actualRanks !== self::RANKS) {
            throw new DomainException('The FUT Champions rank prices contain unsupported ranks.');
        }

        return new self(
            rankPricesHalalah: $rankPrices,
            urgentSurchargeHalalah: self::positiveInteger(
                $configuration['urgent_surcharge_halalah'] ?? null,
                'urgent surcharge',
            ),
        );
    }

    public function priceForRank(int $rank, bool $urgent): int
    {
        $basePrice = $this->rankPricesHalalah[$rank] ?? null;

        if ($basePrice === null) {
            throw new DomainException('The requested FUT Champions rank is unsupported.');
        }

        if (! $urgent) {
            return $basePrice;
        }

        if ($basePrice > PHP_INT_MAX - $this->urgentSurchargeHalalah) {
            throw new DomainException('The FUT Champions price exceeds the supported range.');
        }

        return $basePrice + $this->urgentSurchargeHalalah;
    }

    public function urgentSurcharge(): int
    {
        return $this->urgentSurchargeHalalah;
    }

    private static function positiveInteger(mixed $value, string $field): int
    {
        if (! is_int($value) || $value <= 0) {
            throw new DomainException("The FUT Champions {$field} must be a positive integer.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  list<string>  $expected
     */
    private static function requireExactKeys(array $configuration, array $expected): void
    {
        $actual = array_keys($configuration);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new DomainException('The FUT Champions pricing contains unsupported fields.');
        }
    }
}
