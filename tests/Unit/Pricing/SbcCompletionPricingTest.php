<?php

use App\ValueObjects\Pricing\SbcCompletionPricing;

function completionPricingConfiguration(array $overrides = []): array
{
    $pricing = [
        'version' => 1,
        'repeatable' => true,
        'maximum' => null,
        'tiers' => [
            ['completions' => 5, 'multiplierBps' => 10_000, 'totalMinor' => 57_000],
            ['completions' => 10, 'multiplierBps' => 9_500, 'totalMinor' => 107_900],
            ['completions' => 15, 'multiplierBps' => 9_200, 'totalMinor' => 156_700],
            ['completions' => 20, 'multiplierBps' => 9_000, 'totalMinor' => 204_200],
            ['completions' => 30, 'multiplierBps' => 8_700, 'totalMinor' => 296_000],
            ['completions' => 40, 'multiplierBps' => 8_500, 'totalMinor' => 385_500],
            ['completions' => 50, 'multiplierBps' => 8_200, 'totalMinor' => 464_800],
            ['completions' => 75, 'multiplierBps' => 7_800, 'totalMinor' => 663_100],
            ['completions' => 100, 'multiplierBps' => 7_600, 'totalMinor' => 861_400],
        ],
    ];

    foreach ($overrides as $key => $value) {
        $pricing[$key] = $value;
    }

    return [
        'completionPricing' => $pricing,
    ];
}

it('resolves exact declared SBC completion totals', function () {
    $pricing = SbcCompletionPricing::fromConfiguration(
        completionPricingConfiguration(),
        fallbackMinor: 57_000,
        requireDeclared: true,
    );

    expect($pricing->tierTotal(5))->toBe(57_000)
        ->and($pricing->tierTotal(10))->toBe(107_900)
        ->and($pricing->tierTotal(7))->toBeNull()
        ->and($pricing->completionCounts())->toBe([5, 10, 15, 20, 30, 40, 50, 75, 100])
        ->and($pricing->tiers()[0])->toBe([
            'completions' => 5,
            'multiplierBps' => 10_000,
            'totalMinor' => 57_000,
        ]);
});

it('provides a one-completion compatibility tier only when declaration is optional', function () {
    $pricing = SbcCompletionPricing::fromConfiguration(
        ['sbcCategory' => 'icons'],
        fallbackMinor: 12_500,
        requireDeclared: false,
    );

    expect($pricing->tiers())->toBe([[
        'completions' => 1,
        'multiplierBps' => 10_000,
        'totalMinor' => 12_500,
    ]]);
});

it('rejects a missing declaration at the strict snapshot boundary', function () {
    SbcCompletionPricing::fromConfiguration(
        ['sbcCategory' => 'icons'],
        fallbackMinor: 12_500,
        requireDeclared: true,
    );
})->throws(DomainException::class, 'declared');

it('rejects malformed or out-of-policy declared tiers', function (array $configuration, int $fallbackMinor) {
    SbcCompletionPricing::fromConfiguration(
        $configuration,
        fallbackMinor: $fallbackMinor,
        requireDeclared: true,
    );
})->with([
    'unknown pricing key' => [completionPricingConfiguration(['unexpected' => true]), 57_000],
    'unknown tier key' => [completionPricingConfiguration([
        'tiers' => [[
            'completions' => 5,
            'multiplierBps' => 10_000,
            'totalMinor' => 57_000,
            'unexpected' => true,
        ]],
    ]), 57_000],
    'duplicate completion' => [completionPricingConfiguration([
        'maximum' => 3,
        'tiers' => [
            ['completions' => 1, 'multiplierBps' => 10_000, 'totalMinor' => 11_600],
            ['completions' => 1, 'multiplierBps' => 10_000, 'totalMinor' => 23_000],
            ['completions' => 3, 'multiplierBps' => 10_000, 'totalMinor' => 34_300],
        ],
    ]), 11_600],
    'wrong inserted maximum multiplier' => [completionPricingConfiguration([
        'maximum' => 12,
        'tiers' => [
            ['completions' => 5, 'multiplierBps' => 10_000, 'totalMinor' => 57_000],
            ['completions' => 10, 'multiplierBps' => 9_500, 'totalMinor' => 107_900],
            ['completions' => 12, 'multiplierBps' => 9_200, 'totalMinor' => 126_700],
        ],
    ]), 57_000],
    'nonpositive total' => [completionPricingConfiguration([
        'tiers' => [[
            'completions' => 5,
            'multiplierBps' => 10_000,
            'totalMinor' => 0,
        ]],
    ]), 0],
    'first total mismatch' => [completionPricingConfiguration(), 57_100],
])->throws(DomainException::class);

it('fingerprints only the canonical effective completion prices', function () {
    $first = SbcCompletionPricing::fromConfiguration(
        completionPricingConfiguration(),
        fallbackMinor: 57_000,
        requireDeclared: true,
    );
    $sameWithUnrelatedMetadata = SbcCompletionPricing::fromConfiguration(
        [...completionPricingConfiguration(), 'expiresAt' => '2026-08-20T00:00:00Z'],
        fallbackMinor: 57_000,
        requireDeclared: true,
    );
    $changed = SbcCompletionPricing::fromConfiguration(
        completionPricingConfiguration([
            'tiers' => [
                ['completions' => 5, 'multiplierBps' => 10_000, 'totalMinor' => 57_000],
                ['completions' => 10, 'multiplierBps' => 9_500, 'totalMinor' => 108_000],
                ['completions' => 15, 'multiplierBps' => 9_200, 'totalMinor' => 156_700],
                ['completions' => 20, 'multiplierBps' => 9_000, 'totalMinor' => 204_200],
                ['completions' => 30, 'multiplierBps' => 8_700, 'totalMinor' => 296_000],
                ['completions' => 40, 'multiplierBps' => 8_500, 'totalMinor' => 385_500],
                ['completions' => 50, 'multiplierBps' => 8_200, 'totalMinor' => 464_800],
                ['completions' => 75, 'multiplierBps' => 7_800, 'totalMinor' => 663_100],
                ['completions' => 100, 'multiplierBps' => 7_600, 'totalMinor' => 861_400],
            ],
        ]),
        fallbackMinor: 57_000,
        requireDeclared: true,
    );

    expect($sameWithUnrelatedMetadata->fingerprint())->toBe($first->fingerprint())
        ->and($changed->fingerprint())->not->toBe($first->fingerprint());
});
