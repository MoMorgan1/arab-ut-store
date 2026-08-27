<?php

declare(strict_types=1);

use App\ValueObjects\Pricing\CoinsPricingRule;

/**
 * A configuration that satisfies validateIdentity(): version 1, the matching
 * group, exactly five tier bounds, and the rate field that group requires.
 *
 * `pc` and `console_fast` take tier rates; only `console_normal` takes a flat
 * rate, and supplying the wrong one is an "unsupported field", not a missing one.
 *
 * @return array<string, mixed>
 */
function validPcRuleConfiguration(): array
{
    return [
        'version' => 1,
        'group' => 'pc',
        'tier_upper_bounds_k' => [1_000, 2_000, 5_000, 10_000, 15_000],
        'tier_rates_halalah_per_million' => [1_000, 990, 980, 970, 960, 950],
        'multipliers_basis_points' => [50_000 => 11_000, 60_000 => 10_960],
        'service_fee_halalah' => 300,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ];
}

/** @return array<string, mixed> */
function validConsoleNormalRuleConfiguration(): array
{
    $configuration = validPcRuleConfiguration();
    $configuration['group'] = 'console_normal';
    unset($configuration['tier_rates_halalah_per_million']);
    $configuration['flat_rate_halalah_per_million'] = 1_000;

    return $configuration;
}

it('still answers a threshold multiplier map with the last entry at or below', function () {
    $rule = CoinsPricingRule::fromConfiguration(validPcRuleConfiguration(), 'pc');

    expect($rule->multiplierBasisPoints(50_000))->toBe(11_000)
        ->and($rule->multiplierBasisPoints(55_000))->toBe(11_000)
        ->and($rule->multiplierBasisPoints(60_000))->toBe(10_960);
});

it('still refuses a lookup below the first threshold entry', function () {
    $rule = CoinsPricingRule::fromConfiguration(validPcRuleConfiguration(), 'pc');

    expect(fn () => $rule->multiplierBasisPoints(10_000))
        ->toThrow(DomainException::class, 'No Coins pricing multiplier covers');
});

it('accepts a console_normal rule carrying a flat rate', function () {
    $rule = CoinsPricingRule::fromConfiguration(
        validConsoleNormalRuleConfiguration(),
        'console_normal',
    );

    expect($rule->group)->toBe('console_normal');
});

it('does not reject the anchor field as an unsupported key', function () {
    // validateIdentity() runs before curve parsing, so an allowlist that does
    // not name the anchor field makes the whole feature unreachable.
    $configuration = validPcRuleConfiguration();
    unset($configuration['multipliers_basis_points']);
    $configuration['multiplier_anchors_basis_points'] = [50_000 => 11_000, 1_000_000 => 10_000];

    expect(fn () => CoinsPricingRule::fromConfiguration($configuration, 'pc'))
        ->not->toThrow(DomainException::class, 'unsupported fields');
});

/**
 * @param  array<int, int>|null  $anchors
 * @return array<string, mixed>
 */
function anchoredPcRuleConfiguration(?array $anchors = null): array
{
    $configuration = validPcRuleConfiguration();
    unset($configuration['multipliers_basis_points']);
    $configuration['multiplier_anchors_basis_points'] = $anchors
        ?? [50_000 => 11_000, 100_000 => 10_600];

    return $configuration;
}

it('interpolates when the configuration carries anchors instead of thresholds', function () {
    $rule = CoinsPricingRule::fromConfiguration(anchoredPcRuleConfiguration(), 'pc');

    expect($rule->isAnchored())->toBeTrue()
        ->and($rule->multiplierBasisPoints(75_000))->toBe(10_800)
        ->and($rule->lowestCoveredQuantity())->toBe(50_000)
        ->and($rule->highestCoveredQuantity())->toBe(100_000);
});

it('reports whether the first anchor is the dearest rate on the table', function () {
    // The live curve is V-shaped: it dips at 1M and climbs again, so "dearest"
    // is a property to check, not a shape to assume.
    expect(CoinsPricingRule::fromConfiguration(
        anchoredPcRuleConfiguration([50_000 => 11_000, 1_000_000 => 10_000, 20_000_000 => 10_500]),
        'pc',
    )->firstAnchorIsDearest())->toBeTrue();

    expect(CoinsPricingRule::fromConfiguration(
        anchoredPcRuleConfiguration([50_000 => 10_400, 250_000 => 10_900, 20_000_000 => 10_500]),
        'pc',
    )->firstAnchorIsDearest())->toBeFalse();
});

it('refuses a rule carrying both multiplier shapes', function () {
    $configuration = validPcRuleConfiguration();
    $configuration['multiplier_anchors_basis_points'] = [50_000 => 11_000, 100_000 => 10_600];

    expect(fn () => CoinsPricingRule::fromConfiguration($configuration, 'pc'))
        ->toThrow(DomainException::class, 'exactly one');
});

it('refuses a rule carrying neither multiplier shape', function () {
    $configuration = validPcRuleConfiguration();
    unset($configuration['multipliers_basis_points']);

    expect(fn () => CoinsPricingRule::fromConfiguration($configuration, 'pc'))
        ->toThrow(DomainException::class, 'exactly one');
});

it('reports a threshold rule as not anchored', function () {
    expect(CoinsPricingRule::fromConfiguration(validPcRuleConfiguration(), 'pc')->isAnchored())
        ->toBeFalse();
});

it('treats an explicit null anchor field as a declared shape, not an absent one', function () {
    // Naming the field as null while also carrying thresholds is a payload that
    // cannot decide what it is; accepting it as a threshold rule would hide the
    // contradiction rather than surface it.
    $configuration = validPcRuleConfiguration();
    $configuration['multiplier_anchors_basis_points'] = null;

    expect(fn () => CoinsPricingRule::fromConfiguration($configuration, 'pc'))
        ->toThrow(DomainException::class, 'exactly one');
});
