<?php

declare(strict_types=1);

namespace App\ValueObjects\Pricing;

use DomainException;

/**
 * The Coins quantity multiplier curve, in one of two shapes.
 *
 * A THRESHOLD map is answered with the last entry at or below the quantity, so
 * an entry repeating the one before it is already implied and may be dropped.
 *
 * An ANCHOR table is interpolated linearly between the two bracketing entries,
 * so every published entry is load-bearing and none may be dropped.
 *
 * The distinction matters: collapsing repeats out of an anchor table would
 * silently change prices between the surviving anchors. The two shapes are
 * therefore separate contract fields and never mix.
 */
final readonly class CoinsMultiplierCurve
{
    /** @param non-empty-array<int, int> $points sorted ascending by quantity */
    private function __construct(
        private array $points,
        private bool $interpolated,
    ) {}

    /** @param array<int, int> $map */
    public static function thresholds(array $map): self
    {
        return new self(self::sorted($map), false);
    }

    /** @param array<int, int> $map */
    public static function anchors(array $map): self
    {
        $points = self::sorted($map);

        if (count($points) < 2) {
            throw new DomainException('The Coins multiplier curve needs at least two anchors to interpolate between.');
        }

        return new self($points, true);
    }

    public function isAnchored(): bool
    {
        return $this->interpolated;
    }

    public function lowestCoveredQuantity(): int
    {
        return (int) array_key_first($this->points);
    }

    public function highestCoveredQuantity(): int
    {
        return (int) array_key_last($this->points);
    }

    /**
     * Whether the first anchor carries the highest rate on the table.
     *
     * Quantities below the first anchor clamp to it. That only fails to
     * underprice when the first anchor is the dearest rate; the commercial
     * curve is V-shaped, dipping at one million and climbing again, so this is
     * checked rather than assumed.
     */
    public function firstAnchorIsDearest(): bool
    {
        return $this->points[array_key_first($this->points)] === max($this->points);
    }

    public function basisPointsAt(int $quantity): int
    {
        return $this->interpolated
            ? $this->interpolate($quantity)
            : $this->threshold($quantity);
    }

    private function threshold(int $quantity): int
    {
        $matched = null;

        foreach ($this->points as $from => $basisPoints) {
            if ($from > $quantity) {
                break;
            }

            $matched = $basisPoints;
        }

        if ($matched === null) {
            throw new DomainException('No Coins pricing multiplier covers the requested quantity.');
        }

        return $matched;
    }

    private function interpolate(int $quantity): int
    {
        $lowQuantity = (int) array_key_first($this->points);
        $highQuantity = (int) array_key_last($this->points);

        // Clamping below the first anchor is only safe when that anchor is the
        // dearest rate, which the contract asserts at ingest.
        if ($quantity <= $lowQuantity) {
            return $this->points[$lowQuantity];
        }

        if ($quantity >= $highQuantity) {
            return $this->points[$highQuantity];
        }

        $previousQuantity = $lowQuantity;

        foreach ($this->points as $anchorQuantity => $basisPoints) {
            if ($anchorQuantity === $quantity) {
                return $basisPoints;
            }

            if ($anchorQuantity > $quantity) {
                return self::between(
                    $previousQuantity,
                    $this->points[$previousQuantity],
                    $anchorQuantity,
                    $basisPoints,
                    $quantity,
                );
            }

            $previousQuantity = $anchorQuantity;
        }

        throw new DomainException('No Coins pricing multiplier covers the requested quantity.');
    }

    /**
     * Linear interpolation in integer arithmetic, rounded half up.
     *
     * Kept off floats so the same quantity always yields the same basis point,
     * on every machine, for a value that ends up multiplied into a price.
     *
     * The rounding matches the expansion n8n publishes today, which computes
     * `round(low + delta)` on the positive total and so sends a half upward
     * whichever way the segment slopes. Rounding the shift half-away-from-zero
     * instead would send a descending half down and undercut the published
     * price by a basis point.
     *
     * Overflow is guarded rather than assumed safe: the contract caps basis
     * points only at "> 0", so a corrupt signed payload could otherwise wrap
     * silently and surface as a garbage multiplier on a customer page. The rest
     * of the pricing stack fails closed the same way - see
     * CoinsPriceCalculator::safeMultiply().
     */
    private static function between(
        int $fromQuantity,
        int $fromBasisPoints,
        int $toQuantity,
        int $toBasisPoints,
        int $quantity,
    ): int {
        $span = $toQuantity - $fromQuantity;
        $travelled = $quantity - $fromQuantity;
        $rise = $toBasisPoints - $fromBasisPoints;

        // Both guards run before their multiply, not after it.
        if ($fromBasisPoints !== 0 && $span > intdiv(PHP_INT_MAX, $fromBasisPoints)) {
            throw new DomainException('A Coins multiplier interpolation would overflow a signed 64-bit integer.');
        }

        if ($rise !== 0 && $travelled > intdiv(PHP_INT_MAX, abs($rise))) {
            throw new DomainException('A Coins multiplier interpolation would overflow a signed 64-bit integer.');
        }

        // Scaling the low anchor keeps the half landing upward on both slopes,
        // exactly as the published expansion does.
        return intdiv($fromBasisPoints * $span + $rise * $travelled + intdiv($span, 2), $span);
    }

    /**
     * @param  array<int, int>  $map
     * @return non-empty-array<int, int>
     */
    private static function sorted(array $map): array
    {
        if ($map === []) {
            throw new DomainException('The Coins multiplier curve cannot be empty.');
        }

        ksort($map);

        return $map;
    }
}
