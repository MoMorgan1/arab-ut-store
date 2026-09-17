<?php

use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\ValueObjects\Pricing\SupplierCostTable;

/**
 * A pricing run describes two markets, and one of them can be empty. FC27
 * opened with no PC coins at any price, so the run that finally published
 * carried a console table and an empty PC one. The budget has to read that as
 * "no PC shipment can be priced" rather than "no shipment can be priced".
 */
function costObservations(array $overrides = []): array
{
    return array_replace([
        'ratioEuroUsd' => 1.1,
        'cyclePSUsdPerM' => 2308.6,
        'tierCosts' => [
            'console_fast' => [
                ['targetK' => 1000, 'rawUsdPerM' => 2308.6, 'source' => 'fft_cycle_ps'],
                ['targetK' => 20000, 'rawUsdPerM' => 2400.0, 'source' => 'fft_cycle_ps'],
            ],
            'pc' => [],
        ],
    ], $overrides);
}

it('budgets a console shipment while the PC market is empty', function () {
    $table = SupplierCostTable::fromObservations(7, costObservations());

    expect($table->forCoins(Platform::PlayStation, DeliveryMode::Fast, 500_000))
        ->toBe(['max_eur_per_100k' => 209.87, 'basis' => 'console_fast:1000K'])
        ->and($table->forCoins(Platform::PlayStation, DeliveryMode::Normal, 500_000)['basis'])
        ->toBe('cycle:ps');
});

it('refuses the PC shipment it has no cost for, and says which platform', function () {
    $table = SupplierCostTable::fromObservations(7, costObservations());

    expect(fn () => $table->forCoins(Platform::Pc, DeliveryMode::Fast, 500_000))
        ->toThrow(DomainException::class, 'The pricing run carries no pc cost tiers.');
});

it('refuses a PC challenge for the same reason', function () {
    $table = SupplierCostTable::fromObservations(7, costObservations());

    expect(fn () => $table->forChallenge(Platform::Pc))
        ->toThrow(DomainException::class, 'The pricing run carries no pc cost tiers.');
});

it('reads a platform the run never named as empty, not as malformed', function () {
    $observations = costObservations();
    unset($observations['tierCosts']['pc']);

    $table = SupplierCostTable::fromObservations(7, $observations);

    expect($table->pc)->toBe([])
        ->and($table->consoleFast)->toHaveCount(2);
});

it('still refuses a table that is not a list of tiers', function () {
    $observations = costObservations();
    $observations['tierCosts']['pc'] = ['first' => ['targetK' => 1000, 'rawUsdPerM' => 25.0]];

    expect(fn () => SupplierCostTable::fromObservations(7, $observations))
        ->toThrow(DomainException::class);
});
