<?php

namespace App\ValueObjects\Pricing;

use DomainException;

final readonly class CoinsPricingRule
{
    /**
     * @param  list<int>  $tierUpperBoundsK
     * @param  list<int>  $tierRatesHalalahPerMillion
     * @param  array<int, int>  $multipliersBasisPoints
     * @param  array<int, int>  $exactOverridesHalalah
     */
    private function __construct(
        public string $group,
        private array $tierUpperBoundsK,
        private ?int $flatRateHalalahPerMillion,
        private array $tierRatesHalalahPerMillion,
        private array $multipliersBasisPoints,
        public int $serviceFeeHalalah,
        public int $discountDivisorBasisPoints,
        private array $exactOverridesHalalah,
    ) {}

    /** @param array<string, mixed> $configuration */
    public static function fromConfiguration(array $configuration, string $expectedGroup): self
    {
        self::validateIdentity($configuration, $expectedGroup);
        $bounds = self::tierUpperBounds($configuration['tier_upper_bounds_k'] ?? null);
        [$flatRate, $tierRates] = self::rates($configuration, $expectedGroup);
        $multipliers = self::positiveIntegerMap($configuration['multipliers_basis_points'] ?? null, 'multipliers');

        if ($multipliers === []) {
            throw new DomainException('The Coins pricing multipliers cannot be empty.');
        }

        return new self(
            group: $expectedGroup,
            tierUpperBoundsK: $bounds,
            flatRateHalalahPerMillion: $flatRate,
            tierRatesHalalahPerMillion: $tierRates,
            multipliersBasisPoints: $multipliers,
            serviceFeeHalalah: self::nonnegativeInteger(
                $configuration['service_fee_halalah'] ?? null,
                'service fee',
            ),
            discountDivisorBasisPoints: self::positiveInteger(
                $configuration['discount_divisor_basis_points'] ?? null,
                'discount divisor',
            ),
            exactOverridesHalalah: self::exactOverrides($configuration['exact_overrides_halalah'] ?? null),
        );
    }

    public function exactOverrideHalalah(int $quantity): ?int
    {
        return $this->exactOverridesHalalah[$quantity] ?? null;
    }

    public function multiplierBasisPoints(int $quantity): int
    {
        $matched = null;

        foreach ($this->multipliersBasisPoints as $minimumQuantity => $basisPoints) {
            if ($minimumQuantity > $quantity) {
                break;
            }

            $matched = $basisPoints;
        }

        if ($matched === null) {
            throw new DomainException('No Coins pricing multiplier covers the requested quantity.');
        }

        return $matched;
    }

    public function rateHalalahPerMillion(int $quantity): int
    {
        if ($this->flatRateHalalahPerMillion !== null) {
            return $this->flatRateHalalahPerMillion;
        }

        foreach ($this->tierUpperBoundsK as $index => $upperBoundK) {
            if ($quantity <= $upperBoundK * 1_000) {
                return $this->tierRatesHalalahPerMillion[$index];
            }
        }

        return $this->tierRatesHalalahPerMillion[5];
    }

    /** @param array<string, mixed> $configuration */
    private static function validateIdentity(array $configuration, string $expectedGroup): void
    {
        if (! in_array($expectedGroup, ['console_normal', 'console_fast', 'pc'], true)
            || ($configuration['version'] ?? null) !== 1
            || ($configuration['group'] ?? null) !== $expectedGroup) {
            throw new DomainException('The Coins pricing rule identity is malformed.');
        }

        $rateField = $expectedGroup === 'console_normal'
            ? 'flat_rate_halalah_per_million'
            : 'tier_rates_halalah_per_million';
        $allowedFields = [
            'version', 'group', 'tier_upper_bounds_k', $rateField,
            'multipliers_basis_points', 'service_fee_halalah',
            'discount_divisor_basis_points', 'exact_overrides_halalah',
        ];

        if (array_diff(array_keys($configuration), $allowedFields) !== []) {
            throw new DomainException('The Coins pricing rule contains unsupported fields.');
        }
    }

    /** @return list<int> */
    private static function tierUpperBounds(mixed $configuredBounds): array
    {
        $bounds = self::positiveIntegerList($configuredBounds, 5, 'tier bounds');
        $previous = 0;

        foreach ($bounds as $bound) {
            if ($bound <= $previous || $bound > intdiv(PHP_INT_MAX, 1_000)) {
                throw new DomainException('The Coins pricing tier bounds must be strictly increasing.');
            }

            $previous = $bound;
        }

        return $bounds;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{?int, list<int>}
     */
    private static function rates(array $configuration, string $group): array
    {
        if ($group === 'console_normal') {
            return [
                self::positiveInteger($configuration['flat_rate_halalah_per_million'] ?? null, 'flat rate'),
                [],
            ];
        }

        return [
            null,
            self::positiveIntegerList(
                $configuration['tier_rates_halalah_per_million'] ?? null,
                6,
                'tier rates',
            ),
        ];
    }

    /** @return array<int, int> */
    private static function exactOverrides(mixed $configuredOverrides): array
    {
        $overrides = self::positiveIntegerMap($configuredOverrides, 'exact overrides');

        foreach ($overrides as $override) {
            if ($override < 100 || $override % 100 !== 0) {
                throw new DomainException('A Coins exact override must be at least one whole SAR.');
            }
        }

        return $overrides;
    }

    private static function positiveInteger(mixed $configuredInteger, string $field): int
    {
        if (! is_int($configuredInteger) || $configuredInteger <= 0) {
            throw new DomainException("The Coins pricing {$field} must be a positive integer.");
        }

        return $configuredInteger;
    }

    private static function nonnegativeInteger(mixed $configuredInteger, string $field): int
    {
        if (! is_int($configuredInteger) || $configuredInteger < 0) {
            throw new DomainException("The Coins pricing {$field} must be a nonnegative integer.");
        }

        return $configuredInteger;
    }

    /** @return list<int> */
    private static function positiveIntegerList(mixed $configuredList, int $size, string $field): array
    {
        if (! is_array($configuredList) || ! array_is_list($configuredList) || count($configuredList) !== $size) {
            throw new DomainException("The Coins pricing {$field} has an invalid shape.");
        }

        return array_map(
            fn (mixed $configuredInteger): int => self::positiveInteger($configuredInteger, $field),
            $configuredList,
        );
    }

    /** @return array<int, int> */
    private static function positiveIntegerMap(mixed $configuredMap, string $field): array
    {
        if (! is_array($configuredMap)) {
            throw new DomainException("The Coins pricing {$field} has an invalid shape.");
        }

        $normalized = [];

        foreach ($configuredMap as $quantity => $configuredInteger) {
            if ((is_int($quantity) && $quantity > 0)
                || (is_string($quantity) && ctype_digit($quantity) && (int) $quantity > 0)) {
                $normalizedQuantity = (int) $quantity;
            } else {
                throw new DomainException("The Coins pricing {$field} quantity must be a positive integer.");
            }

            $normalizedInteger = self::positiveInteger($configuredInteger, $field);

            if (array_key_exists($normalizedQuantity, $normalized)) {
                throw new DomainException("The Coins pricing {$field} contains a duplicate quantity.");
            }

            $normalized[$normalizedQuantity] = $normalizedInteger;
        }

        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}
