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

    public function calculate(
        CoinsPricingRule $rule,
        int $quantity,
        ?CoinsPricingRule $normalRule = null,
    ): Money {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A Coins quantity must be positive.');
        }

        $override = $rule->exactOverrideHalalah($quantity);

        if ($override !== null) {
            return Money::fromHalalah($override);
        }

        $total = $this->calculateFormula($rule, $quantity);

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
        $sarDenominator = $this->safeMultiply($halalahDenominator, self::HALALAH_PER_SAR);
        $roundedSar = max($this->roundRatio($rawNumerator, $sarDenominator), 1);

        return Money::fromHalalah($this->safeMultiply($roundedSar, self::HALALAH_PER_SAR));
    }

    private function percentageFloor(Money $normal): int
    {
        $normalSar = intdiv($normal->halalah(), self::HALALAH_PER_SAR);
        $roundedSar = $this->roundRatio($this->safeMultiply($normalSar, 105), 100);

        return $this->safeMultiply($roundedSar, self::HALALAH_PER_SAR);
    }

    private function perMillionFloor(Money $normal, int $quantity): int
    {
        $normalSar = intdiv($normal->halalah(), self::HALALAH_PER_SAR);
        $numerator = $this->safeAdd(
            $this->safeMultiply($normalSar, self::COINS_PER_MILLION),
            $this->safeMultiply($quantity, 5),
        );
        $roundedSar = $this->roundRatio($numerator, self::COINS_PER_MILLION);

        return $this->safeMultiply($roundedSar, self::HALALAH_PER_SAR);
    }

    private function addVisibleSar(Money $normal): int
    {
        return $this->safeAdd($normal->halalah(), self::HALALAH_PER_SAR);
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
