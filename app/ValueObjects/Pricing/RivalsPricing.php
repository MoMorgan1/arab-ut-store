<?php

namespace App\ValueObjects\Pricing;

use DomainException;

final readonly class RivalsPricing
{
    private const LADDER = ['7', '6', '5', '4', '3', '2', '1', 'elite'];

    private const STEPS = ['7:6', '6:5', '5:4', '4:3', '3:2', '2:1', '1:elite'];

    /**
     * fromConfiguration() rejects any configuration that does not declare every
     * ladder step exactly once, so the map is never empty here.
     *
     * Weekly matches are the same service on the same account with the same
     * credentials - we play the week without promoting a division - so they are
     * a second way to buy Rivals rather than a service of their own. They are
     * optional: until the price and the included wins are set, the option is
     * not offered at all, which is what stops a placeholder price selling.
     *
     * @param  non-empty-array<string, int>  $stepPricesHalalah
     * @param  array{priceHalalah:int,includedWins:int}|null  $weeklyMatches
     */
    private function __construct(
        private array $stepPricesHalalah,
        private ?array $weeklyMatches = null,
    ) {}

    /** @param array<string, mixed> $configuration */
    public static function fromConfiguration(array $configuration): self
    {
        $keys = array_keys($configuration);
        sort($keys);

        if ($keys !== ['steps'] && $keys !== ['steps', 'weeklyMatches']) {
            throw new DomainException('The Rivals pricing contains unsupported fields.');
        }

        $configuredSteps = $configuration['steps'] ?? null;

        if (! is_array($configuredSteps)) {
            throw new DomainException('The Rivals step prices have an invalid shape.');
        }

        $actualSteps = array_keys($configuredSteps);
        $expectedSteps = self::STEPS;
        sort($actualSteps);
        sort($expectedSteps);

        if ($actualSteps !== $expectedSteps) {
            throw new DomainException('Every supported Rivals step must be declared exactly once.');
        }

        $stepPrices = [];

        foreach (self::STEPS as $step) {
            $price = $configuredSteps[$step];

            if (! is_int($price) || $price <= 0) {
                throw new DomainException('Every Rivals step price must be a positive integer.');
            }

            $stepPrices[$step] = $price;
        }

        return new self($stepPrices, self::parseWeeklyMatches($configuration['weeklyMatches'] ?? null));
    }

    /**
     * Whether the weekly-matches option is on sale at all.
     */
    public function offersWeeklyMatches(): bool
    {
        return $this->weeklyMatches !== null;
    }

    public function weeklyMatchesPriceHalalah(): int
    {
        if ($this->weeklyMatches === null) {
            throw new DomainException('Weekly matches are not on sale.');
        }

        return $this->weeklyMatches['priceHalalah'];
    }

    public function weeklyMatchesIncludedWins(): int
    {
        if ($this->weeklyMatches === null) {
            throw new DomainException('Weekly matches are not on sale.');
        }

        return $this->weeklyMatches['includedWins'];
    }

    /**
     * @return array{priceHalalah:int,includedWins:int}|null
     */
    private static function parseWeeklyMatches(mixed $configured): ?array
    {
        // Absent means not offered. Present but half-filled is a mistake worth
        // refusing: a price with no win count promises the customer nothing,
        // and a win count with no price cannot be sold.
        if ($configured === null) {
            return null;
        }

        if (! is_array($configured)) {
            throw new DomainException('The Rivals weekly-matches pricing has an invalid shape.');
        }

        $keys = array_keys($configured);
        sort($keys);

        if ($keys !== ['includedWins', 'priceHalalah']) {
            throw new DomainException('Weekly matches must declare exactly a price and an included win count.');
        }

        $price = $configured['priceHalalah'];
        $wins = $configured['includedWins'];

        if (! is_int($price) || $price <= 0) {
            throw new DomainException('The Rivals weekly-matches price must be a positive integer.');
        }

        if (! is_int($wins) || $wins <= 0) {
            throw new DomainException('The Rivals weekly-matches included wins must be a positive integer.');
        }

        return ['priceHalalah' => $price, 'includedWins' => $wins];
    }

    public function priceForRoute(string $from, string $to): int
    {
        $fromIndex = self::divisionIndex($from);
        $toIndex = self::divisionIndex($to);

        if ($toIndex <= $fromIndex) {
            throw new DomainException('The Rivals target must be higher than the current division.');
        }

        $total = 0;

        for ($index = $fromIndex; $index < $toIndex; $index++) {
            $step = self::LADDER[$index].':'.self::LADDER[$index + 1];
            $price = $this->stepPricesHalalah[$step];

            if ($total > PHP_INT_MAX - $price) {
                throw new DomainException('The Rivals route price exceeds the supported range.');
            }

            $total += $price;
        }

        return $total;
    }

    /** @return list<string> */
    public function availableTargets(string $from): array
    {
        return array_slice(self::LADDER, self::divisionIndex($from) + 1);
    }

    public function cheapestStepHalalah(): int
    {
        return min($this->stepPricesHalalah);
    }

    private static function divisionIndex(string $division): int
    {
        $index = array_search($division, self::LADDER, true);

        if ($index === false) {
            throw new DomainException('The requested Rivals division is unsupported.');
        }

        return $index;
    }
}
