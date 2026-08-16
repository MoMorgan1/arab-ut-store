<?php

use App\ValueObjects\Pricing\FutChampionsPricing;

function approvedFutChampionsPricing(array $overrides = []): array
{
    return array_replace([
        'ranks' => [
            '1' => 22_000,
            '2' => 19_000,
            '3' => 17_000,
            '4' => 15_000,
            '5' => 13_000,
            '6' => 10_000,
        ],
        'urgent_surcharge_halalah' => 4_000,
    ], $overrides);
}

it('prices every approved FUT Champions rank with the optional urgent surcharge', function () {
    $pricing = FutChampionsPricing::fromConfiguration(approvedFutChampionsPricing());

    expect($pricing->priceForRank(1, false))->toBe(22_000)
        ->and($pricing->priceForRank(2, false))->toBe(19_000)
        ->and($pricing->priceForRank(3, false))->toBe(17_000)
        ->and($pricing->priceForRank(4, false))->toBe(15_000)
        ->and($pricing->priceForRank(5, false))->toBe(13_000)
        ->and($pricing->priceForRank(6, false))->toBe(10_000)
        ->and($pricing->priceForRank(1, true))->toBe(26_000)
        ->and($pricing->priceForRank(6, true))->toBe(14_000)
        ->and($pricing->urgentSurcharge())->toBe(4_000);
});

it('rejects malformed FUT Champions schedules', function (array $configuration) {
    FutChampionsPricing::fromConfiguration($configuration);
})->with([
    'missing rank' => fn () => approvedFutChampionsPricing([
        'ranks' => [1 => 22_000, 2 => 19_000, 3 => 17_000, 4 => 15_000, 5 => 13_000],
    ]),
    'extra rank' => fn () => approvedFutChampionsPricing([
        'ranks' => [1 => 22_000, 2 => 19_000, 3 => 17_000, 4 => 15_000, 5 => 13_000, 6 => 10_000, 7 => 9_000],
    ]),
    'noninteger rank price' => fn () => approvedFutChampionsPricing([
        'ranks' => [1 => 22_000, 2 => 19_000, 3 => '17000', 4 => 15_000, 5 => 13_000, 6 => 10_000],
    ]),
    'nonpositive rank price' => fn () => approvedFutChampionsPricing([
        'ranks' => [1 => 22_000, 2 => 19_000, 3 => 17_000, 4 => 0, 5 => 13_000, 6 => 10_000],
    ]),
    'nonpositive urgent surcharge' => fn () => approvedFutChampionsPricing(['urgent_surcharge_halalah' => 0]),
    'unknown field' => fn () => [...approvedFutChampionsPricing(), 'currency' => 'SAR'],
])->throws(DomainException::class);

it('rejects FUT Champions ranks outside the supported range', function (int $rank) {
    FutChampionsPricing::fromConfiguration(approvedFutChampionsPricing())
        ->priceForRank($rank, false);
})->with([0, 7])->throws(DomainException::class);
