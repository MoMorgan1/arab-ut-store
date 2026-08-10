<?php

namespace App\Actions\Pricing;

use App\Models\ExchangeRate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DomainException;

final class ConvertDisplayMoney
{
    private const RATE_SCALE = 100_000_000;

    /** @return array{amountMinor: int, currency: string} */
    public function execute(Money $money, string $displayCurrency): array
    {
        if (! in_array($displayCurrency, config('store.display_currencies'), true)) {
            throw new DomainException('The requested display currency is unsupported.');
        }

        if ($displayCurrency === 'SAR') {
            return ['amountMinor' => $money->halalah(), 'currency' => 'SAR'];
        }

        $exchangeRate = ExchangeRate::query()
            ->where('base_currency', 'SAR')
            ->where('quote_currency', $displayCurrency)
            ->first();

        $maximumAge = (int) config('store.display_exchange_rates.max_age_hours');

        $fetchedAt = $exchangeRate?->getAttribute('fetched_at');

        if (! $fetchedAt instanceof CarbonImmutable
            || $fetchedAt->lte(now()->subHours($maximumAge))) {
            throw new DomainException('A fresh display exchange rate is unavailable.');
        }

        $scaledRate = $this->parseScaledRate($exchangeRate->rate);
        $halalah = $money->halalah();

        if ($scaledRate !== 0 && $halalah > intdiv(PHP_INT_MAX, $scaledRate)) {
            throw new DomainException('The display money conversion would overflow.');
        }

        $scaledAmount = $halalah * $scaledRate;
        $amountMinor = intdiv($scaledAmount, self::RATE_SCALE);

        if ($scaledAmount % self::RATE_SCALE >= intdiv(self::RATE_SCALE, 2)) {
            if ($amountMinor === PHP_INT_MAX) {
                throw new DomainException('The display money conversion would overflow.');
            }

            $amountMinor++;
        }

        if ($amountMinor <= 0) {
            throw new DomainException('The display money conversion is too small.');
        }

        return ['amountMinor' => $amountMinor, 'currency' => $displayCurrency];
    }

    private function parseScaledRate(mixed $rate): int
    {
        if (! is_string($rate) || preg_match('/^(?<whole>\d{1,12})\.(?<fraction>\d{8})$/D', $rate, $matches) !== 1) {
            throw new DomainException('The display exchange rate is invalid.');
        }

        $digits = ltrim($matches['whole'].$matches['fraction'], '0');

        if ($digits === '') {
            throw new DomainException('The display exchange rate must be positive.');
        }

        $scaledRate = 0;

        foreach (str_split($digits) as $digit) {
            $value = ord($digit) - ord('0');

            if ($scaledRate > intdiv(PHP_INT_MAX - $value, 10)) {
                throw new DomainException('The display exchange rate is too large.');
            }

            $scaledRate = ($scaledRate * 10) + $value;
        }

        return $scaledRate;
    }
}
