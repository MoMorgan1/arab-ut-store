<?php

use App\Enums\DeliveryPhase;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\OrderItem;

function fulfillmentPlacementsMigration(): object
{
    return require database_path('migrations/2026_09_12_000003_create_fulfillment_placements.php');
}

test('the backfill copies a legacy bound job into exactly one first placement', function (): void {
    $migration = fulfillmentPlacementsMigration();
    $migration->down();

    $item = OrderItem::factory()->create();
    $job = FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'FFT-LEGACY-COINS',
        'delivery_phase' => null,
        'created_at' => '2026-08-20 09:15:00',
    ]);

    $migration->up();

    $placement = FulfillmentPlacement::sole();

    expect($placement->fulfillment_job_id)->toBe($job->id)
        ->and($placement->delivery_phase)->toBe(DeliveryPhase::Coins)
        ->and($placement->supplier)->toBe(Supplier::Fft)
        ->and($placement->supplier_order_id)->toBe('FFT-LEGACY-COINS')
        ->and($placement->idempotency_key)->toBe('fulfillment-placement:'.$item->public_id.':coins')
        ->and($placement->placed_at->toDateTimeString())->toBe('2026-08-20 09:15:00');
});

test('the backfill keeps a recorded legacy phase and defaults a missing one to coins', function (): void {
    $migration = fulfillmentPlacementsMigration();
    $migration->down();

    $coinsItem = OrderItem::factory()->create();
    $coinsJob = FulfillmentJob::factory()->create([
        'order_item_id' => $coinsItem->id,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'FFT-LEGACY-NO-PHASE',
        'delivery_phase' => null,
    ]);

    $challengeItem = OrderItem::factory()->create();
    $challengeJob = FulfillmentJob::factory()->create([
        'order_item_id' => $challengeItem->id,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'FFT-LEGACY-CHALLENGE',
        'delivery_phase' => DeliveryPhase::Challenge,
    ]);

    $migration->up();

    $placements = FulfillmentPlacement::query()
        ->get()
        ->keyBy(fn (FulfillmentPlacement $placement): string => $placement->delivery_phase->value);

    expect($placements)->toHaveCount(2)
        ->and($placements['coins']->fulfillment_job_id)->toBe($coinsJob->id)
        ->and($placements['coins']->idempotency_key)->toBe('fulfillment-placement:'.$coinsItem->public_id.':coins')
        ->and($placements['challenge']->fulfillment_job_id)->toBe($challengeJob->id)
        ->and($placements['challenge']->idempotency_key)->toBe('fulfillment-placement:'.$challengeItem->public_id.':challenge');
});

test('the backfill skips legacy jobs with no supplier reference', function (): void {
    $migration = fulfillmentPlacementsMigration();
    $migration->down();

    $missingSupplier = FulfillmentJob::factory()->create([
        'supplier' => null,
        'supplier_order_id' => 'FFT-ORPHAN-REFERENCE',
    ]);

    $missingReference = FulfillmentJob::factory()->create([
        'supplier' => Supplier::Fft,
        'supplier_order_id' => null,
    ]);

    $migration->up();

    expect(FulfillmentPlacement::count())->toBe(0)
        ->and(FulfillmentPlacement::query()->where('fulfillment_job_id', $missingSupplier->id)->doesntExist())->toBeTrue()
        ->and(FulfillmentPlacement::query()->where('fulfillment_job_id', $missingReference->id)->doesntExist())->toBeTrue();
});
