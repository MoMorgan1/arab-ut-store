<?php

use App\Enums\DeliveryPhase;
use App\Enums\OrderHoldReason;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Models\FulfillmentJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('adds every supplier observation column to fulfillment jobs', function (): void {
    $columns = [
        'delivery_phase',
        'observed_state',
        'observation',
        'observed_at',
        'observation_supported',
        'coins_delivered',
        'coins_ordered',
        'squads_done',
        'squads_total',
        'solves_done',
        'solves_total',
        'hold_reason',
        'allowed_actions',
        'last_viewed_at',
        'lease_token',
        'leased_until',
        'poll_failure_count',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('fulfillment_jobs', $column))
            ->toBeTrue("fulfillment_jobs is missing [{$column}]");
    }
});

it('round trips a full supplier observation through the database', function (): void {
    $observedAt = now()->subMinute();
    $observation = [
        'state' => 'challenge_running',
        'coins' => ['delivered' => 250_000, 'ordered' => 250_000],
        'challenges' => ['solved' => 3, 'requested' => 10],
    ];

    $job = FulfillmentJob::factory()->create([
        'supplier' => Supplier::Fft,
        'delivery_phase' => DeliveryPhase::Challenge,
        'observed_state' => 'challenge_running',
        'observation' => $observation,
        'observed_at' => $observedAt,
        'hold_reason' => OrderHoldReason::Credentials,
        'allowed_actions' => [SupplierAction::EditCredentials->value, SupplierAction::Resume->value],
        'coins_delivered' => 250_000,
        'coins_ordered' => 250_000,
        'squads_done' => 3,
        'squads_total' => 10,
        'solves_done' => 1,
        'solves_total' => 2,
    ]);

    $fresh = FulfillmentJob::query()->findOrFail($job->id);

    expect($fresh->supplier)->toBe(Supplier::Fft)
        ->and($fresh->delivery_phase)->toBe(DeliveryPhase::Challenge)
        ->and($fresh->observed_state)->toBe('challenge_running')
        ->and($fresh->observation)->toBe($observation)
        ->and($fresh->observed_at)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($fresh->observed_at?->getTimestamp())->toBe($observedAt->getTimestamp())
        ->and($fresh->hold_reason)->toBe(OrderHoldReason::Credentials)
        ->and($fresh->allowed_actions)->toBe([SupplierAction::EditCredentials->value, SupplierAction::Resume->value])
        ->and($fresh->coins_delivered)->toBe(250_000)
        ->and($fresh->coins_ordered)->toBe(250_000)
        ->and($fresh->squads_done)->toBe(3)
        ->and($fresh->squads_total)->toBe(10)
        ->and($fresh->solves_done)->toBe(1)
        ->and($fresh->solves_total)->toBe(2);
});

it('maps stored allowed actions to enum cases and drops stale values', function (): void {
    $job = FulfillmentJob::factory()->create([
        'allowed_actions' => [
            SupplierAction::Resume->value,
            'retired_action',
            SupplierAction::RetryChallenge->value,
        ],
    ]);

    expect($job->fresh()->allowedActions())
        ->toBe([SupplierAction::Resume, SupplierAction::RetryChallenge]);
});

it('returns an empty allowed-action list when the column is null', function (): void {
    $job = FulfillmentJob::factory()->create();

    expect($job->fresh()->allowedActions())->toBe([]);
});

it('supports supplier observations by default', function (): void {
    $job = FulfillmentJob::factory()->create();

    expect($job->fresh()->observation_supported)->toBeTrue();
});

it('still allows exactly one fulfillment job per order item', function (): void {
    $job = FulfillmentJob::factory()->create();

    expect(fn () => FulfillmentJob::factory()->create(['order_item_id' => $job->order_item_id]))
        ->toThrow(QueryException::class);
});
