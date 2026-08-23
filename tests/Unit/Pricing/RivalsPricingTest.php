<?php

use App\ValueObjects\Pricing\RivalsPricing;

function approvedRivalsPricing(array $overrides = []): array
{
    return array_replace([
        'steps' => [
            '7:6' => 11_000,
            '6:5' => 12_000,
            '5:4' => 13_000,
            '4:3' => 14_000,
            '3:2' => 15_000,
            '2:1' => 16_000,
            '1:elite' => 17_000,
        ],
    ], $overrides);
}

it('sums every Rivals step between the current and target divisions', function () {
    $pricing = RivalsPricing::fromConfiguration(approvedRivalsPricing());

    expect($pricing->priceForRoute('7', '6'))->toBe(11_000)
        ->and($pricing->priceForRoute('2', '1'))->toBe(16_000)
        ->and($pricing->priceForRoute('1', 'elite'))->toBe(17_000)
        ->and($pricing->priceForRoute('5', 'elite'))->toBe(75_000)
        ->and($pricing->priceForRoute('7', 'elite'))->toBe(98_000);
});

it('returns only strictly higher Rivals targets in ladder order', function () {
    $pricing = RivalsPricing::fromConfiguration(approvedRivalsPricing());

    expect($pricing->availableTargets('7'))->toBe(['6', '5', '4', '3', '2', '1', 'elite'])
        ->and($pricing->availableTargets('5'))->toBe(['4', '3', '2', '1', 'elite'])
        ->and($pricing->availableTargets('1'))->toBe(['elite'])
        ->and($pricing->availableTargets('elite'))->toBe([]);
});

it('rejects malformed Rivals schedules', function (array $configuration) {
    RivalsPricing::fromConfiguration($configuration);
})->with([
    'missing step' => fn () => approvedRivalsPricing([
        'steps' => ['7:6' => 11_000, '6:5' => 12_000, '5:4' => 13_000, '4:3' => 14_000, '3:2' => 15_000, '2:1' => 16_000],
    ]),
    'extra step' => fn () => approvedRivalsPricing([
        'steps' => [...approvedRivalsPricing()['steps'], 'elite:super' => 18_000],
    ]),
    'noninteger step price' => fn () => approvedRivalsPricing([
        'steps' => [...approvedRivalsPricing()['steps'], '5:4' => '13000'],
    ]),
    'nonpositive step price' => fn () => approvedRivalsPricing([
        'steps' => [...approvedRivalsPricing()['steps'], '3:2' => 0],
    ]),
    'unknown field' => fn () => [...approvedRivalsPricing(), 'currency' => 'SAR'],
])->throws(DomainException::class);

it('rejects unknown, equal, or lower Rivals routes', function (string $from, string $to) {
    RivalsPricing::fromConfiguration(approvedRivalsPricing())->priceForRoute($from, $to);
})->with([
    ['bronze', 'elite'],
    ['7', 'diamond'],
    ['5', '5'],
    ['3', '4'],
    ['elite', 'elite'],
])->throws(DomainException::class);

it('rejects an unknown current division when listing targets', function () {
    RivalsPricing::fromConfiguration(approvedRivalsPricing())->availableTargets('bronze');
})->throws(DomainException::class);

it('returns the cheapest single ladder step in halalah', function () {
    $pricing = RivalsPricing::fromConfiguration(approvedRivalsPricing());

    expect($pricing->cheapestStepHalalah())->toBe(11_000);
});

it('returns the lowest step price regardless of ladder position', function () {
    $pricing = RivalsPricing::fromConfiguration(approvedRivalsPricing([
        'steps' => [
            '7:6' => 25_000,
            '6:5' => 20_000,
            '5:4' => 9_000,
            '4:3' => 18_000,
            '3:2' => 16_000,
            '2:1' => 14_000,
            '1:elite' => 22_000,
        ],
    ]));

    expect($pricing->cheapestStepHalalah())->toBe(9_000);
});
