<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\ServicePriceSchedule;
use App\Services\Catalog\CoinsCatalogReader;
use App\ValueObjects\Pricing\CoinsMultiplierCurve;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The commercial anchors live in the n8n export's Config node, which the owner
 * edits directly - they are commercial values, not code. Nothing else in this
 * suite reads them from there, so without this test the anchors could drift out
 * of the export and every acceptance test would keep passing against a constant
 * that no longer matches what the workflow publishes.
 *
 * @return array<int, int>
 */
function workflowExportAnchors(): array
{
    $export = json_decode(
        (string) file_get_contents(base_path('automation/n8n/coins-pricing-v2/workflow-v2.4.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $config = collect($export['nodes'])->firstWhere('name', 'Config')['parameters']['jsCode'];

    expect($config)->toContain('quantityCommercialUpliftAnchorsBps');

    preg_match('/quantityCommercialUpliftAnchorsBps = \{(.*?)\};/s', $config, $block);
    preg_match_all('/([\d_]+):\s*([\d_]+),/', $block[1] ?? '', $pairs, PREG_SET_ORDER);

    $anchors = [];

    foreach ($pairs as [, $quantity, $basisPoints]) {
        $anchors[(int) str_replace('_', '', $quantity)] = (int) str_replace('_', '', $basisPoints);
    }

    ksort($anchors);

    return $anchors;
}

it('publishes an anchor field the contract recognises', function () {
    $export = json_decode(
        (string) file_get_contents(base_path('automation/n8n/coins-pricing-v2/workflow-v2.4.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $code = collect($export['nodes'])
        ->filter(fn (array $node): bool => isset($node['parameters']['jsCode']))
        ->map(fn (array $node): string => $node['parameters']['jsCode'])
        ->implode("\n");

    // The threshold field would be silently accepted and would reintroduce the
    // frozen-expansion problem, so its absence is the guarantee worth pinning.
    expect($code)->toContain('multiplier_anchors_basis_points')
        ->and($code)->not->toContain('multipliers_basis_points');
});

it('accepts the anchors exactly as the export publishes them', function () {
    $anchors = workflowExportAnchors();

    expect($anchors)->toHaveCount(11)
        ->and(array_key_first($anchors))->toBe(50_000)
        ->and(array_key_last($anchors))->toBe(20_000_000);

    // The floor the owner lowered on 2026-08-26, which the export still declares
    // as 50,000. An anchor curve is meant to be accepted anyway.
    $schedule = ServicePriceSchedule::query()
        ->where('service_type', ServiceType::Coins)
        ->where('is_active', true)
        ->firstOrFail();

    $configuration = (array) $schedule->configuration;
    $configuration['minimum'] = 10_000;
    $schedule->update(['configuration' => $configuration]);

    postN8nSnapshot(n8nAnchoredSnapshot(declaredMinimum: 50_000, anchors: $anchors))
        ->assertCreated()
        ->assertJsonPath('data.status', 'applied');

    $rule = app(CoinsCatalogReader::class)->pricingRules(['pc'])['pc'];

    expect($rule->multiplierBasisPoints(10_000))->toBe(11_000)
        ->and($rule->multiplierBasisPoints(200_000))->toBe(10_400)
        ->and($rule->multiplierBasisPoints(20_000_000))->toBe(10_500);
});

it('agrees with the export interpolation at every point on the buyable grid', function () {
    // Both sides interpolate the same anchors - n8n to prove the schedule never
    // descends, Laravel to serve it. A disagreement anywhere means the schedule
    // n8n validated is not the one customers are charged.
    $anchors = workflowExportAnchors();
    $curve = CoinsMultiplierCurve::anchors($anchors);
    $quantities = array_keys($anchors);

    $mismatches = [];

    for ($quantity = 5_000; $quantity <= 20_000_000; $quantity += 5_000) {
        // The float arithmetic the export's own multiplierFor() performs.
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

        $expected ??= $anchors[20_000_000];

        if ($curve->basisPointsAt($quantity) !== $expected) {
            $mismatches[] = "{$quantity}: php {$curve->basisPointsAt($quantity)} vs js {$expected}";
        }
    }

    expect($mismatches)->toBe([], implode(' | ', array_slice($mismatches, 0, 5)));
});
