<?php

use App\Actions\Pricing\ConvertDisplayMoney;
use App\Models\ExchangeRate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function storeDisplayRate(string $currency, string $rate, CarbonImmutable $fetchedAt): ExchangeRate
{
    return ExchangeRate::create([
        'base_currency' => 'SAR',
        'quote_currency' => $currency,
        'rate' => $rate,
        'source' => 'exchange-rate-api-open-access',
        'fetched_at' => $fetchedAt,
    ]);
}

test('SAR display money preserves authoritative integer halalah without a rate', function () {
    expect(app(ConvertDisplayMoney::class)->execute(Money::fromHalalah(650), 'SAR'))
        ->toBe(['amountMinor' => 650, 'currency' => 'SAR']);
});

test('a fresh canonical rate converts integer halalah with exact half-up rounding', function (
    string $rate,
    int $expected,
) {
    CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
    storeDisplayRate('USD', $rate, now()->toImmutable());

    expect(app(ConvertDisplayMoney::class)->execute(Money::fromHalalah(100), 'USD'))
        ->toBe(['amountMinor' => $expected, 'currency' => 'USD']);
})->with([
    'below half' => ['0.01499999', 1],
    'at half' => ['0.01500000', 2],
    'above half' => ['0.01500001', 2],
]);

test('a non-whole foreign amount is converted from the persisted decimal string', function () {
    CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
    storeDisplayRate('USD', '0.26666667', now()->toImmutable());

    expect(app(ConvertDisplayMoney::class)->execute(Money::fromHalalah(600), 'USD'))
        ->toBe(['amountMinor' => 160, 'currency' => 'USD']);
});

test('a positive foreign amount that rounds to zero minor units fails closed', function () {
    CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
    storeDisplayRate('USD', '0.00100000', now()->toImmutable());

    expect(fn () => app(ConvertDisplayMoney::class)->execute(Money::fromHalalah(1), 'USD'))
        ->toThrow(DomainException::class);
});

test('a missing stale or overflowing foreign rate fails closed', function (string $failure) {
    CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');

    if ($failure === 'stale') {
        storeDisplayRate('EUR', '0.25000000', now()->subHours(30)->toImmutable());
    }

    if ($failure === 'overflow') {
        storeDisplayRate('EUR', '2.00000000', now()->toImmutable());
    }

    $money = $failure === 'overflow'
        ? Money::fromHalalah(PHP_INT_MAX)
        : Money::fromHalalah(600);

    expect(fn () => app(ConvertDisplayMoney::class)->execute($money, 'EUR'))
        ->toThrow(DomainException::class);
})->with(['missing', 'stale', 'overflow']);

test('a rate immediately inside the 30 hour boundary remains usable', function () {
    CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
    storeDisplayRate('GBP', '0.20000000', now()->subHours(30)->addSecond()->toImmutable());

    expect(app(ConvertDisplayMoney::class)->execute(Money::fromHalalah(600), 'GBP'))
        ->toBe(['amountMinor' => 120, 'currency' => 'GBP']);
});
