<?php

use App\Services\Pricing\CoinsPriceCalculator;
use App\ValueObjects\Pricing\CoinsPricingRule;

/**
 * The commercial uplift curve the pricing run publishes, as its authors write it:
 * eleven anchors, linearly interpolated at every legal quantity in between. One
 * million is the promotional low point, so the curve falls and then climbs again -
 * which matters here, because a value that leaves and later returns must be written
 * again rather than treated as already said.
 */
const COINS_UPLIFT_ANCHORS = [
    50_000 => 11_000,
    100_000 => 10_600,
    150_000 => 10_500,
    250_000 => 10_300,
    500_000 => 10_200,
    1_000_000 => 10_000,
    2_000_000 => 10_150,
    5_000_000 => 10_250,
    10_000_000 => 10_350,
    15_000_000 => 10_400,
    20_000_000 => 10_500,
];

/** @return array<int, int> */
function denseCoinsMultipliers(int $minimum, int $maximum, int $increment): array
{
    $anchors = COINS_UPLIFT_ANCHORS;
    $quantities = array_keys($anchors);
    $map = [];

    for ($quantity = $minimum; $quantity <= $maximum; $quantity += $increment) {
        $map[$quantity] = $anchors[$quantities[count($quantities) - 1]];

        foreach ($quantities as $index => $right) {
            if ($quantity > $right) {
                continue;
            }

            $left = $index === 0 ? $right : $quantities[$index - 1];
            $map[$quantity] = $left === $right
                ? $anchors[$right]
                : (int) round($anchors[$left]
                    + ($anchors[$right] - $anchors[$left]) * (($quantity - $left) / ($right - $left)));

            break;
        }
    }

    return $map;
}

/**
 * @param  array<int, int>  $multipliers
 * @return array<string, mixed>
 */
function coinsRuleWithMultipliers(string $group, array $multipliers): array
{
    $configuration = [
        'version' => 1,
        'group' => $group,
        'tier_upper_bounds_k' => [1_000, 2_000, 5_000, 10_000, 15_000],
        'multipliers_basis_points' => $multipliers,
        'service_fee_halalah' => 300,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ];

    if ($group === 'console_normal') {
        $configuration['flat_rate_halalah_per_million'] = 5_000;

        return $configuration;
    }

    $configuration['tier_rates_halalah_per_million'] = [5_000, 5_500, 6_000, 6_500, 7_000, 7_500];

    return $configuration;
}

test('a collapsed multiplier map prices every legal quantity exactly like the dense map it came from', function (
    string $group,
    int $maximum,
) {
    $increment = 5_000;
    $calculator = new CoinsPriceCalculator;

    $dense = coinsRuleWithMultipliers($group, denseCoinsMultipliers(50_000, $maximum, $increment));
    $collapsed = CoinsPricingRule::withoutRedundantMultipliers($dense);

    $denseRule = CoinsPricingRule::fromConfiguration($dense, $group);
    $collapsedRule = CoinsPricingRule::fromConfiguration($collapsed, $group);

    // Fast delivery is floored against normal, so the normal rule is priced too -
    // above its own two-million ceiling, where the carried-forward entry is the
    // only thing answering.
    $denseNormal = $group === 'console_fast'
        ? CoinsPricingRule::fromConfiguration(
            coinsRuleWithMultipliers('console_normal', denseCoinsMultipliers(50_000, 2_000_000, $increment)),
            'console_normal',
        )
        : null;
    $collapsedNormal = $denseNormal === null
        ? null
        : CoinsPricingRule::fromConfiguration(
            CoinsPricingRule::withoutRedundantMultipliers(
                coinsRuleWithMultipliers('console_normal', denseCoinsMultipliers(50_000, 2_000_000, $increment)),
            ),
            'console_normal',
        );

    $mismatches = [];

    for ($quantity = 50_000; $quantity <= $maximum; $quantity += $increment) {
        $expected = $calculator->calculate($denseRule, $quantity, $denseNormal)->halalah();
        $actual = $calculator->calculate($collapsedRule, $quantity, $collapsedNormal)->halalah();

        if ($expected !== $actual) {
            $mismatches[$quantity] = [$expected, $actual];
        }
    }

    expect($mismatches)->toBe([])
        ->and(count($collapsed['multipliers_basis_points']))
        ->toBeLessThan(count($dense['multipliers_basis_points']));
})->with([
    'console normal, to two million' => ['console_normal', 2_000_000],
    'console fast, to twenty million' => ['console_fast', 20_000_000],
    'pc, to twenty million' => ['pc', 20_000_000],
]);

test('collapsing keeps the entry the range starts on, so the lowest quantity is still covered', function () {
    $dense = coinsRuleWithMultipliers('pc', denseCoinsMultipliers(50_000, 20_000_000, 5_000));
    $collapsed = CoinsPricingRule::withoutRedundantMultipliers($dense);

    expect(array_key_first($collapsed['multipliers_basis_points']))->toBe(50_000)
        ->and(CoinsPricingRule::fromConfiguration($collapsed, 'pc')->multiplierBasisPoints(50_000))
        ->toBe(11_000);
});

test('collapsing an already collapsed map changes nothing', function () {
    $once = CoinsPricingRule::withoutRedundantMultipliers(
        coinsRuleWithMultipliers('pc', denseCoinsMultipliers(50_000, 20_000_000, 5_000)),
    );

    expect(CoinsPricingRule::withoutRedundantMultipliers($once))->toBe($once);
});

test('a value that returns after a different one is written again', function () {
    // Falling and then climbing back through the same basis points is exactly what
    // the promotional dip at one million does. Comparing against the last value
    // *written* rather than the smallest seen is what keeps that correct.
    $collapsed = CoinsPricingRule::withoutRedundantMultipliers(
        coinsRuleWithMultipliers('pc', [
            50_000 => 10_500,
            55_000 => 10_500,
            60_000 => 10_000,
            65_000 => 10_500,
        ]),
    );

    expect($collapsed['multipliers_basis_points'])
        ->toBe([50_000 => 10_500, 60_000 => 10_000, 65_000 => 10_500]);
});

test('dense and collapsed agree off the grid and refuse the same quantities below it', function () {
    // Nothing prices an off-grid quantity today, but the equivalence does not
    // depend on the grid and the next caller should not have to rediscover that.
    $dense = coinsRuleWithMultipliers('pc', denseCoinsMultipliers(50_000, 20_000_000, 5_000));
    $denseRule = CoinsPricingRule::fromConfiguration($dense, 'pc');
    $collapsedRule = CoinsPricingRule::fromConfiguration(
        CoinsPricingRule::withoutRedundantMultipliers($dense),
        'pc',
    );

    foreach ([50_001, 54_999, 999_999, 1_000_001, 3_141_593, 19_999_999, 20_000_001] as $quantity) {
        expect($collapsedRule->multiplierBasisPoints($quantity))
            ->toBe($denseRule->multiplierBasisPoints($quantity), "at {$quantity}");
    }

    // And below the first entry neither can answer, rather than one guessing.
    expect(fn () => $denseRule->multiplierBasisPoints(49_999))->toThrow(DomainException::class)
        ->and(fn () => $collapsedRule->multiplierBasisPoints(49_999))->toThrow(DomainException::class);
});

it('leaves an anchor table untouched', function () {
    // Collapsing a repeat out of an anchor table would make interpolation
    // invent a value between the survivors that was never published.
    $configuration = [
        'group' => 'pc',
        'multiplier_anchors_basis_points' => [
            50_000 => 11_000,
            55_000 => 11_000,
            60_000 => 10_960,
        ],
    ];

    expect(CoinsPricingRule::withoutRedundantMultipliers($configuration))->toBe($configuration);
});
