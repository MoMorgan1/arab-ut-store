<?php

namespace App\Actions\Pricing;

use App\Services\Pricing\CoinsPriceCalculator;
use App\ValueObjects\Pricing\CoinsPricingRule;
use App\ValueObjects\Pricing\CoinsQuantityRules;

/**
 * The smallest quantity a customer can buy for at least a given amount of money.
 *
 * Quantities are the wrong unit to hold a commercial decision in. "Start at
 * fifty thousand coins" was a sound floor while a million cost seven riyals and
 * an absurd one the morning a million cost eight hundred - the same number, the
 * same words, a floor that moved from a third of a riyal to forty without
 * anybody deciding it should. Prices are what the decision is actually about:
 * the smallest order worth the work of placing it, the quick amounts worth
 * offering. Those hold their meaning across a season turn, so they are what the
 * store stores, and the quantity is solved for on every read.
 *
 * Solved against the reference rate - slow console delivery - because the floor
 * is a quantity shared by every platform and one of them has to be the
 * yardstick. A PC buyer sees the same coin count at their own higher price.
 */
final readonly class SolveCoinsQuantityForPrice
{
    public function __construct(private CoinsPriceCalculator $calculator) {}

    /**
     * The cheapest buyable quantity costing at least `$halalah`.
     *
     * Never below the configured floor, and never above the ceiling: a target
     * nothing in range can reach lands on the nearest end rather than throwing,
     * because a storefront that refuses to render is worse than one showing a
     * quick amount slightly off the money.
     */
    public function execute(
        CoinsPricingRule $rule,
        CoinsQuantityRules $rules,
        int $halalah,
    ): int {
        $unit = $rules->roundingUnit();
        $low = intdiv($rules->minimum(), $unit);
        $high = intdiv($rules->maximum(), $unit);

        if ($this->costOf($rule, $low * $unit) >= $halalah) {
            return $low * $unit;
        }

        if ($this->costOf($rule, $high * $unit) < $halalah) {
            return $high * $unit;
        }

        // The price rises with the quantity, so the answer is a boundary and a
        // bisection finds it in about two dozen calculations rather than the
        // four thousand a walk up the range would take.
        while ($high - $low > 1) {
            $middle = intdiv($low + $high, 2);

            if ($this->costOf($rule, $middle * $unit) >= $halalah) {
                $high = $middle;
            } else {
                $low = $middle;
            }
        }

        return $high * $unit;
    }

    private function costOf(CoinsPricingRule $rule, int $quantity): int
    {
        return $this->calculator->calculate($rule, $quantity)->halalah();
    }
}
