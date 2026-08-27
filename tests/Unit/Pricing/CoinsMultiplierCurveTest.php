<?php

declare(strict_types=1);

use App\ValueObjects\Pricing\CoinsMultiplierCurve;

it('answers a threshold map with the last entry at or below the quantity', function () {
    $curve = CoinsMultiplierCurve::thresholds([50_000 => 11_000, 60_000 => 10_960]);

    expect($curve->basisPointsAt(50_000))->toBe(11_000)
        ->and($curve->basisPointsAt(55_000))->toBe(11_000)
        ->and($curve->basisPointsAt(60_000))->toBe(10_960);
});

it('interpolates an anchor table linearly between the bracketing anchors', function () {
    $curve = CoinsMultiplierCurve::anchors([50_000 => 11_000, 100_000 => 10_600]);

    // Halfway between the anchors is halfway between their values.
    expect($curve->basisPointsAt(75_000))->toBe(10_800)
        ->and($curve->basisPointsAt(50_000))->toBe(11_000)
        ->and($curve->basisPointsAt(100_000))->toBe(10_600);
});

it('rounds a half upward on an ascending segment, as the n8n expansion does', function () {
    // Midpoint of 10_000..10_001 is 10_000.5 -> 10_001.
    expect(CoinsMultiplierCurve::anchors([0 => 10_000, 2 => 10_001])->basisPointsAt(1))
        ->toBe(10_001);
});

it('rounds a half upward on a descending segment too', function () {
    // Midpoint of 11_000..10_999 is 10_999.5. The published expansion computes
    // round(11_000 + -0.5) = 11_000, so rounding the shift away from zero here
    // would undercut it by a basis point.
    expect(CoinsMultiplierCurve::anchors([0 => 11_000, 2 => 10_999])->basisPointsAt(1))
        ->toBe(11_000);
});

it('clamps a quantity below the lowest anchor to the lowest anchor', function () {
    $curve = CoinsMultiplierCurve::anchors([50_000 => 11_000, 1_000_000 => 10_000]);

    expect($curve->basisPointsAt(10_000))->toBe(11_000);
});

it('clamps a quantity above the highest anchor to the highest anchor', function () {
    $curve = CoinsMultiplierCurve::anchors([50_000 => 11_000, 1_000_000 => 10_000]);

    expect($curve->basisPointsAt(5_000_000))->toBe(10_000);
});

it('reports the range it covers', function () {
    $curve = CoinsMultiplierCurve::anchors([50_000 => 11_000, 2_000_000 => 10_000]);

    expect($curve->lowestCoveredQuantity())->toBe(50_000)
        ->and($curve->highestCoveredQuantity())->toBe(2_000_000)
        ->and(CoinsMultiplierCurve::thresholds([50_000 => 11_000])->highestCoveredQuantity())
        ->toBe(50_000);
});

it('reports whether its first anchor is the dearest rate', function () {
    // The live curve dips at one million and climbs again, so being dearest is
    // a property to check rather than a shape to assume.
    expect(CoinsMultiplierCurve::anchors([50_000 => 11_000, 1_000_000 => 10_000, 20_000_000 => 10_500])
        ->firstAnchorIsDearest())->toBeTrue()
        ->and(CoinsMultiplierCurve::anchors([50_000 => 10_400, 250_000 => 10_900])
            ->firstAnchorIsDearest())->toBeFalse();
});

it('sorts anchors supplied out of order', function () {
    $curve = CoinsMultiplierCurve::anchors([100_000 => 10_600, 50_000 => 11_000]);

    expect($curve->basisPointsAt(75_000))->toBe(10_800)
        ->and($curve->lowestCoveredQuantity())->toBe(50_000);
});

it('refuses an anchor table with fewer than two anchors', function () {
    CoinsMultiplierCurve::anchors([50_000 => 11_000]);
})->throws(DomainException::class, 'at least two anchors');

it('refuses an empty curve', function () {
    CoinsMultiplierCurve::thresholds([]);
})->throws(DomainException::class, 'cannot be empty');

it('refuses a threshold lookup below its first entry', function () {
    CoinsMultiplierCurve::thresholds([50_000 => 11_000])->basisPointsAt(10_000);
})->throws(DomainException::class, 'No Coins pricing multiplier covers');

it('reproduces the published expansion at every grid point of the live anchors', function () {
    // The whole migration rests on this: an anchored run must price identically
    // to the expansion n8n publishes today, or switching the workflow moves
    // prices that are correct right now.
    $anchors = [
        50_000 => 11_000, 100_000 => 10_600, 150_000 => 10_500, 250_000 => 10_300,
        500_000 => 10_200, 1_000_000 => 10_000, 2_000_000 => 10_150,
        5_000_000 => 10_250, 10_000_000 => 10_350, 15_000_000 => 10_400,
        20_000_000 => 10_500,
    ];

    $curve = CoinsMultiplierCurve::anchors($anchors);
    $quantities = array_keys($anchors);

    for ($quantity = 50_000; $quantity <= 20_000_000; $quantity += 5_000) {
        // The same arithmetic the workflow uses, mirrored in tests/Support.
        $expected = null;

        foreach ($quantities as $index => $right) {
            if ($quantity > $right) {
                continue;
            }

            $left = $index === 0 ? $right : $quantities[$index - 1];
            $expected = $left === $right
                ? $anchors[$right]
                : (int) round($anchors[$left]
                    + ($anchors[$right] - $anchors[$left]) * (($quantity - $left) / ($right - $left)));

            break;
        }

        expect($curve->basisPointsAt($quantity))->toBe($expected, "quantity {$quantity}");
    }
});
