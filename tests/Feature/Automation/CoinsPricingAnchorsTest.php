<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\ServicePriceSchedule;
use App\Services\Catalog\CoinsCatalogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Lower the admin floor the way the owner did on 2026-08-26.
 *
 * The seeding migration creates an active Coins schedule with minimum 50,000,
 * so this updates a row that really exists. CoinsCatalogReader is not bound as
 * a singleton and memoises per instance, so the next request reads the new value.
 */
function lowerAdminMinimumTo(int $minimum): void
{
    $schedule = ServicePriceSchedule::query()
        ->where('service_type', ServiceType::Coins)
        ->where('is_active', true)
        ->firstOrFail();

    $configuration = (array) $schedule->configuration;
    $configuration['minimum'] = $minimum;

    $schedule->update(['configuration' => $configuration]);
}

it('accepts an anchor payload whose declared minimum differs from the admin floor', function () {
    // The exact drift that froze pricing: the admin floor moved to 10,000 and
    // the workflow still declares 50,000.
    lowerAdminMinimumTo(10_000);

    postN8nSnapshot(n8nAnchoredSnapshot(declaredMinimum: 50_000))
        ->assertCreated()
        ->assertJsonPath('data.status', 'applied');
});

it('prices a quantity below the published anchors by clamping to the first anchor', function () {
    lowerAdminMinimumTo(10_000);
    postN8nSnapshot(n8nAnchoredSnapshot())->assertCreated();

    // 11,000 bp is both the first anchor and the dearest rate, so a 10,000-coin
    // order is charged at the top of the curve rather than failing to price.
    $rule = app(CoinsCatalogReader::class)->pricingRules(['pc'])['pc'];

    expect($rule->multiplierBasisPoints(10_000))->toBe(11_000);
});

it('rejects an anchor table that stops short of the group maximum', function () {
    // Clamping above the top anchor would price a 20M order at the 2M rate.
    postN8nSnapshot(n8nAnchoredSnapshot(anchors: [50_000 => 11_000, 2_000_000 => 10_150]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rules.pc']);
});

it('rejects an anchor table whose first anchor is above the floor and not the dearest', function () {
    // {5M => 10350, 20M => 10500} passes top coverage yet clamps every order
    // from 10,000 to 5,000,000 down to 10,350 - a 100K order charged x1.035
    // instead of x1.06.
    lowerAdminMinimumTo(10_000);

    postN8nSnapshot(n8nAnchoredSnapshot(anchors: [5_000_000 => 10_350, 20_000_000 => 10_500]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rules.pc']);
});

it('still compares the declared minimum for a threshold payload', function () {
    // A threshold map cannot answer below its first entry, so its declared
    // minimum must keep matching the admin floor.
    lowerAdminMinimumTo(10_000);

    postN8nSnapshot(n8nSnapshot(increment: 5_000))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['legalRanges.pc']);
});
