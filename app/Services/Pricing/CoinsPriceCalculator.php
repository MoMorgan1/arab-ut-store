<?php

namespace App\Services\Pricing;

use App\Support\Money;
use App\ValueObjects\Pricing\CoinsPricingRule;
use DomainException;
use InvalidArgumentException;

final class CoinsPriceCalculator
{
    private const COINS_PER_MILLION = 1_000_000;

    private const BASIS_POINTS = 10_000;

    private const HALALAH_PER_SAR = 100;

    /**
     * Display prices are quoted at a 0.1-SAR grain so nearby quantities stay
     * distinguishable instead of collapsing onto the same whole-riyal price.
     */
    private const DISPLAY_GRAIN_HALALAH = 10;

    public function calculate(
        CoinsPricingRule $rule,
        int $quantity,
        ?CoinsPricingRule $normalRule = null,
    ): Money {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A Coins quantity must be positive.');
        }

        $override = $rule->exactOverrideHalalah($quantity);

        if ($override !== null && $rule->group !== 'console_fast') {
            return Money::fromHalalah($override);
        }

        // A pinned fast price is a candidate, not an exemption. It still clears the
        // floors below, so a bad pricing run can never publish fast delivery cheaper
        // than normal for the same quantity.
        $total = $override !== null
            ? Money::fromHalalah($override)
            : $this->calculateFormula($rule, $quantity);

        if ($rule->group !== 'console_fast') {
            return $total;
        }

        if ($normalRule === null || $normalRule->group !== 'console_normal') {
            throw new DomainException('Fast Coins pricing requires the active normal pricing rule.');
        }

        $normal = $this->calculateFormula($normalRule, $quantity);

        return Money::fromHalalah(max(
            $total->halalah(),
            $this->percentageFloor($normal),
            $this->perMillionFloor($normal, $quantity),
            $this->addVisibleSar($normal),
        ));
    }

    private function calculateFormula(CoinsPricingRule $rule, int $quantity): Money
    {
        $rateNumerator = $this->safeMultiply(
            $this->safeMultiply($quantity, $rule->rateHalalahPerMillion($quantity)),
            $rule->multiplierBasisPoints($quantity),
        );
        $feeGap = max(0, self::COINS_PER_MILLION - $quantity);
        $feeNumerator = $this->safeMultiply(
            $this->safeMultiply($rule->serviceFeeHalalah, $feeGap),
            self::BASIS_POINTS,
        );
        $rawNumerator = $this->safeAdd($rateNumerator, $feeNumerator);
        $halalahDenominator = $this->safeMultiply(
            self::COINS_PER_MILLION,
            $rule->discountDivisorBasisPoints,
        );
        $exactHalalah = $this->roundRatio($rawNumerator, $halalahDenominator);
        $roundedHalalah = max(
            $this->roundToDisplayGrain($exactHalalah),
            self::HALALAH_PER_SAR,
        );

        return Money::fromHalalah($roundedHalalah);
    }

    private function percentageFloor(Money $normal): int
    {
        $normalGrain = intdiv($normal->halalah(), self::DISPLAY_GRAIN_HALALAH);
        $roundedGrain = $this->roundRatio($this->safeMultiply($normalGrain, 105), 100);

        return $this->safeMultiply($roundedGrain, self::DISPLAY_GRAIN_HALALAH);
    }

    private function perMillionFloor(Money $normal, int $quantity): int
    {
        $numerator = $this->safeAdd(
            $this->safeMultiply($normal->halalah(), self::COINS_PER_MILLION),
            $this->safeMultiply($quantity, 500),
        );
        $exactHalalah = $this->roundRatio($numerator, self::COINS_PER_MILLION);

        return $this->roundToDisplayGrain($exactHalalah);
    }

    private function addVisibleSar(Money $normal): int
    {
        return $this->safeAdd($normal->halalah(), self::HALALAH_PER_SAR);
    }

    private function roundToDisplayGrain(int $halalah): int
    {
        return $this->roundRatio($halalah, self::DISPLAY_GRAIN_HALALAH)
            * self::DISPLAY_GRAIN_HALALAH;
    }

    private function roundRatio(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new DomainException('A Coins pricing divisor must be positive.');
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        $half = intdiv($denominator, 2) + ($denominator % 2);

        return $remainder >= $half ? $this->safeAdd($quotient, 1) : $quotient;
    }

    private function safeMultiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new DomainException('Coins pricing cannot use negative arithmetic inputs.');
        }

        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new DomainException('A Coins pricing operation would overflow a signed 64-bit integer.');
        }

        return $left * $right;
    }

    private function safeAdd(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $right > PHP_INT_MAX - $left) {
            throw new DomainException('A Coins pricing operation would overflow a signed 64-bit integer.');
        }

        return $left + $right;
    }
}
