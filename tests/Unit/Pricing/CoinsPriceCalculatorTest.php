<?php

use App\Services\Pricing\CoinsPriceCalculator;
use App\ValueObjects\Pricing\CoinsPricingRule;

/** @param array<string, mixed> $changes */
function pricingRule(array $changes = []): CoinsPricingRule
{
    return CoinsPricingRule::fromConfiguration(array_replace([
        'version' => 1,
        'group' => 'console_normal',
        'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
        'flat_rate_halalah_per_million' => 5_000,
        'multipliers_basis_points' => [
            '50000' => 11_000,
            '100000' => 10_000,
        ],
        'service_fee_halalah' => 300,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ], $changes), 'console_normal');
}

/** @param array<string, mixed> $changes */
function fastPricingRule(array $changes = []): CoinsPricingRule
{
    return CoinsPricingRule::fromConfiguration(array_replace([
        'version' => 1,
        'group' => 'console_fast',
        'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
        'tier_rates_halalah_per_million' => array_fill(0, 6, 1),
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 0,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ], $changes), 'console_fast');
}

test('pricing uses integer halalah for the multiplier fee divisor and display-grain rounding', function () {
    $rule = pricingRule([
        'discount_divisor_basis_points' => 8_000,
    ]);

    $total = (new CoinsPriceCalculator)->calculate($rule, 50_000);

    // ((0.05M coins × 50 SAR/M × 1.10) + (3 SAR × 0.95)) / 0.80 = 7.0 SAR exactly.
    expect($total->halalah())->toBe(700)
        ->and($total->currency())->toBe('SAR');
});

test('an exact quantity override takes precedence over the tier formula', function () {
    $rule = pricingRule([
        'exact_overrides_halalah' => ['50000' => 1_200],
    ]);

    expect((new CoinsPriceCalculator)->calculate($rule, 50_000)->halalah())
        ->toBe(1_200);
});

test('sub-riyal overrides are accepted on the 0.1-SAR display grid', function () {
    $rule = pricingRule([
        'exact_overrides_halalah' => ['50000' => 150],
    ]);

    expect((new CoinsPriceCalculator)->calculate($rule, 50_000)->halalah())
        ->toBe(150);
});

test('a pinned fast price is lifted to the fast floors instead of undercutting normal', function () {
    // A pricing run could pin any positive multiple of 0.1 SAR — 1.50 SAR for
    // 50,000 coins here — and it used to be served raw, below the normal price
    // for the same quantity. The floors now apply to pinned prices too.
    $normal = pricingRule();
    $fast = CoinsPricingRule::fromConfiguration([
        'version' => 1,
        'group' => 'console_fast',
        'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
        'tier_rates_halalah_per_million' => array_fill(0, 6, 5_000),
        'multipliers_basis_points' => ['50000' => 11_000, '100000' => 10_000],
        'service_fee_halalah' => 300,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => ['50000' => 150],
    ], 'console_fast');

    $calculator = new CoinsPriceCalculator;
    $normalTotal = $calculator->calculate($normal, 50_000)->halalah();
    $fastTotal = $calculator->calculate($fast, 50_000, $normal)->halalah();

    expect($fastTotal)->toBeGreaterThan(150)
        ->and($fastTotal)->toBeGreaterThan($normalTotal)
        ->and($fastTotal)->toBeGreaterThanOrEqual($normalTotal + 100);
});

test('a pinned fast price above the floors is served exactly as pinned', function () {
    $normal = pricingRule();
    $fast = CoinsPricingRule::fromConfiguration([
        'version' => 1,
        'group' => 'console_fast',
        'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
        'tier_rates_halalah_per_million' => array_fill(0, 6, 5_000),
        'multipliers_basis_points' => ['50000' => 11_000, '100000' => 10_000],
        'service_fee_halalah' => 300,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => ['50000' => 99_000],
    ], 'console_fast');

    expect((new CoinsPriceCalculator)->calculate($fast, 50_000, $normal)->halalah())
        ->toBe(99_000);
});

test('exact overrides must preserve the positive 0.1-SAR price boundary', function (int $override) {
    expect(fn () => pricingRule([
        'exact_overrides_halalah' => ['50000' => $override],
    ]))->toThrow(DomainException::class);
})->with([
    'zero would permit a free order' => 0,
    'less than one display grain' => 5,
    'off the 0.1 SAR grid' => 123,
]);

test('tier upper bounds are inclusive and the sixth rate is open ended', function (int $quantity, int $expectedHalalah) {
    $rule = CoinsPricingRule::fromConfiguration([
        'version' => 1,
        'group' => 'pc',
        'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
        'tier_rates_halalah_per_million' => [10_000, 20_000, 30_000, 40_000, 50_000, 60_000],
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 0,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ], 'pc');

    expect((new CoinsPriceCalculator)->calculate($rule, $quantity)->halalah())
        ->toBe($expectedHalalah);
})->with([
    'last coin in tier one' => [100_000, 1_000],
    'first valid step in tier two' => [110_000, 2_200],
    'last configured boundary' => [5_000_000, 250_000],
    'open-ended tier six' => [5_010_000, 300_600],
]);

test('formula results round to the 0.1-SAR grain and retain the one-SAR minimum', function () {
    $calculator = new CoinsPriceCalculator;
    $keepsHalfRiyal = pricingRule([
        'flat_rate_halalah_per_million' => 5_100,
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 0,
    ]);
    $floorsAtOne = pricingRule([
        'flat_rate_halalah_per_million' => 1,
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 0,
    ]);

    expect($calculator->calculate($keepsHalfRiyal, 500_000)->halalah())->toBe(2_550)
        ->and($calculator->calculate($floorsAtOne, 50_000)->halalah())->toBe(100);
});

test('fast delivery enforces the current visible gap above the normal result', function () {
    $normal = pricingRule([
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 0,
    ]);
    $fast = CoinsPricingRule::fromConfiguration([
        'version' => 1,
        'group' => 'console_fast',
        'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
        'tier_rates_halalah_per_million' => array_fill(0, 6, 5_000),
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 0,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ], 'console_fast');

    expect((new CoinsPriceCalculator)->calculate($fast, 1_000_000, $normal)->halalah())
        ->toBe(5_500);
});

test('the five-percent fast floor is decisive for a high normal price at low quantity', function () {
    $normal = pricingRule([
        'flat_rate_halalah_per_million' => 200_000,
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 0,
    ]);

    expect((new CoinsPriceCalculator)->calculate(fastPricingRule(), 50_000, $normal)->halalah())
        ->toBe(10_500);
});

test('the visible one-SAR fast floor is decisive when percentage and per-million gaps round away', function () {
    $normal = pricingRule([
        'flat_rate_halalah_per_million' => 10_000,
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 0,
    ]);

    expect((new CoinsPriceCalculator)->calculate(fastPricingRule(), 50_000, $normal)->halalah())
        ->toBe(600);
});

test('malformed rule data fails closed instead of supplying defaults', function (array $configuration, string $group) {
    expect(fn () => CoinsPricingRule::fromConfiguration($configuration, $group))
        ->toThrow(DomainException::class);
})->with([
    'mismatched group' => [[
        'version' => 1,
        'group' => 'pc',
    ], 'console_normal'],
    'missing multiplier map' => [[
        'version' => 1,
        'group' => 'console_normal',
        'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
        'flat_rate_halalah_per_million' => 5_000,
        'service_fee_halalah' => 300,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ], 'console_normal'],
    'unordered tier bounds' => [[
        'version' => 1,
        'group' => 'pc',
        'tier_upper_bounds_k' => [100, 500, 400, 2000, 5000],
        'tier_rates_halalah_per_million' => array_fill(0, 6, 5_000),
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 300,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ], 'pc'],
    'unexpected legacy metadata' => [[
        'version' => 1,
        'group' => 'console_normal',
        'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
        'flat_rate_halalah_per_million' => 5_000,
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 300,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
        'computed_at' => '2026-08-09T00:00:00Z',
    ], 'console_normal'],
]);

test('pricing rejects signed integer overflow before evaluating it', function () {
    $rule = pricingRule([
        'flat_rate_halalah_per_million' => PHP_INT_MAX,
        'multipliers_basis_points' => ['50000' => PHP_INT_MAX],
        'service_fee_halalah' => PHP_INT_MAX,
    ]);

    expect(fn () => (new CoinsPriceCalculator)->calculate($rule, 20_000_000))
        ->toThrow(DomainException::class, 'overflow');
});
