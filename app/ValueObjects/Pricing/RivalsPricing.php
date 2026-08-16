<?php

namespace App\ValueObjects\Pricing;

use DomainException;

final readonly class RivalsPricing
{
    private const LADDER = ['7', '6', '5', '4', '3', '2', '1', 'elite'];

    private const STEPS = ['7:6', '6:5', '5:4', '4:3', '3:2', '2:1', '1:elite'];

    /** @param array<string, int> $stepPricesHalalah */
    private function __construct(private array $stepPricesHalalah) {}

    /** @param array<string, mixed> $configuration */
    public static function fromConfiguration(array $configuration): self
    {
        if (array_keys($configuration) !== ['steps']) {
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

        return new self($stepPrices);
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

    private static function divisionIndex(string $division): int
    {
        $index = array_search($division, self::LADDER, true);

        if ($index === false) {
            throw new DomainException('The requested Rivals division is unsupported.');
        }

        return $index;
    }
}
