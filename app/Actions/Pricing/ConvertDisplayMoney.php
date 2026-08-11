<?php

namespace App\Actions\Pricing;

use App\Models\ExchangeRate;
use App\Support\Money;
use App\ValueObjects\Pricing\PreparedDisplayMoneyConverter;
use Carbon\CarbonImmutable;
use DomainException;

final class ConvertDisplayMoney
{
    /** @return array{amountMinor: int, currency: string} */
    public function execute(Money $money, string $displayCurrency): array
    {
        return $this->prepare($displayCurrency)->convert($money);
    }

    public function prepare(string $displayCurrency): PreparedDisplayMoneyConverter
    {
        if (! in_array($displayCurrency, config('store.display_currencies'), true)) {
            throw new DomainException('The requested display currency is unsupported.');
        }

        if ($displayCurrency === 'SAR') {
            return PreparedDisplayMoneyConverter::sar();
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

        return PreparedDisplayMoneyConverter::fromRate($displayCurrency, $exchangeRate->rate);
    }
}
