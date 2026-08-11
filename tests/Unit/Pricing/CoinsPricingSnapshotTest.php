<?php

use App\ValueObjects\Pricing\CoinsPricingRule;

test('the approved Coins rule shape supports five configurable tier boundaries and a sixth open tier', function () {
    $configuration = [
        'version' => 1,
        'group' => 'pc',
        'tier_upper_bounds_k' => [1000, 2000, 5000, 10000, 15000],
        'tier_rates_halalah_per_million' => [5_000, 5_500, 6_000, 6_500, 7_000, 7_500],
        'multipliers_basis_points' => ['50000' => 10_000, '10000000' => 10_500],
        'service_fee_halalah' => 300,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ];

    $rule = CoinsPricingRule::fromConfiguration($configuration, 'pc');

    expect($rule->rateHalalahPerMillion(15_000_000))->toBe(7_000)
        ->and($rule->rateHalalahPerMillion(20_000_000))->toBe(7_500)
        ->and($rule->multiplierBasisPoints(20_000_000))->toBe(10_500);
});
