<?php

use App\Actions\Fulfillment\SweepFulfillmentAlarms;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentAlarmKind;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\Supplier;
use App\Models\FulfillmentAlarm;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;

/** @return array{raised: int, resolved: int, open: int} */
function sweepForStalls(): array
{
    return app(SweepFulfillmentAlarms::class)->execute();
}

/**
 * A placed job whose newest reading landed a given number of minutes ago, or
 * never (null).
 *
 * @param  array<string, mixed>  $attributes
 */
function placedJobLastRead(?int $minutesAgo, array $attributes = [], string $orderNumber = 'AUT-STALL-1'): FulfillmentJob
{
    $order = paidOrder($orderNumber);
    $item = coinsItem($order);

    return FulfillmentJob::factory()->live()->create(array_replace([
        'order_item_id' => $item->id,
        'supplier' => Supplier::Utt,
        'supplier_order_id' => 'UTT-'.$orderNumber,
        'delivery_phase' => DeliveryPhase::Coins,
        'observed_state' => 'entered',
        'observed_at' => $minutesAgo === null ? null : now()->subMinutes($minutesAgo),
    ], $attributes));
}

/** The whole cadence table, so a test states every phase it relies on. */
function cadenceTable(?int $coins = null, ?int $challenge = null, ?int $none = null): void
{
    config()->set('services.suppliers.alarm.stalled_after_minutes', [
        'coins' => $coins,
        'challenge' => $challenge,
        'none' => $none,
    ]);
}

test('an unset cadence table detects nothing, however long the silence', function () {
    // The shipped configuration, unchanged: no phase carries a number yet.
    placedJobLastRead(30 * 24 * 60);

    expect(sweepForStalls())->toMatchArray(['raised' => 0, 'resolved' => 0, 'open' => 0]);
    expect(FulfillmentAlarm::query()->count())->toBe(0);
});

test('a threshold that is not a positive number is unset, not instant', function (mixed $value) {
    config()->set('services.suppliers.alarm.stalled_after_minutes', ['coins' => $value]);
    placedJobLastRead(30 * 24 * 60);

    expect(sweepForStalls()['raised'])->toBe(0);
    expect(FulfillmentAlarm::query()->count())->toBe(0);
})->with([
    'zero' => [0],
    'negative' => [-60],
    'a word where a number belongs' => ['soon'],
    'an empty string' => [''],
]);

test('the whole table missing is the same as every entry being unset', function () {
    config()->set('services.suppliers.alarm.stalled_after_minutes', null);
    placedJobLastRead(30 * 24 * 60);

    expect(sweepForStalls()['raised'])->toBe(0);
});

test('a job inside its phase cadence raises nothing', function () {
    cadenceTable(coins: 60);
    placedJobLastRead(10);

    expect(sweepForStalls()['raised'])->toBe(0);
    expect(FulfillmentAlarm::query()->count())->toBe(0);
});

test('a job past its phase cadence raises once and not again', function () {
    cadenceTable(coins: 60);
    $job = placedJobLastRead(90);

    expect(sweepForStalls())->toMatchArray(['raised' => 1, 'resolved' => 0, 'open' => 1]);

    $alarm = FulfillmentAlarm::query()->sole();
    expect($alarm->kind)->toBe(FulfillmentAlarmKind::Stalled)
        ->and($alarm->order_item_id)->toBe($job->order_item_id)
        ->and($alarm->context['phase'] ?? null)->toBe('coins')
        ->and($alarm->context['observed_state'] ?? null)->toBe('entered')
        ->and($alarm->context['supplier'] ?? null)->toBe('utt')
        ->and($alarm->context['quiet_minutes'] ?? null)->toBe(90);

    $raisedAt = $alarm->raised_at;

    // A second sweep finds the same stall. It must not count it again, and it
    // must not restamp the age an operator is reading off the panel.
    expect(sweepForStalls())->toMatchArray(['raised' => 0, 'resolved' => 0, 'open' => 1]);
    expect(FulfillmentAlarm::query()->sole()->raised_at->toDateTimeString())
        ->toBe($raisedAt->toDateTimeString());
});

test('the alarm resolves when an observation lands', function () {
    cadenceTable(coins: 60);
    $job = placedJobLastRead(90);
    sweepForStalls();

    // Exactly what one successful read does to the row.
    $job->forceFill(['observed_at' => now()])->save();

    expect(sweepForStalls())->toMatchArray(['raised' => 0, 'resolved' => 1, 'open' => 0]);
    expect(FulfillmentAlarm::query()->sole()->resolved_at)->not->toBeNull();
});

test('the cadence is read from the row for the job own phase', function () {
    // Challenges are watched, coins are not. A coins job must not borrow the
    // challenge number, which is the whole reason the table has rows at all.
    cadenceTable(coins: null, challenge: 60);

    placedJobLastRead(90, [], 'AUT-STALL-COINS');
    placedJobLastRead(90, ['delivery_phase' => DeliveryPhase::Challenge], 'AUT-STALL-SBC');

    expect(sweepForStalls())->toMatchArray(['raised' => 1, 'open' => 1]);
    expect(FulfillmentAlarm::query()->sole()->context['phase'] ?? null)->toBe('challenge');
});

test('a job carrying no phase is watched by the none row', function () {
    cadenceTable(coins: null, none: 60);
    placedJobLastRead(90, ['delivery_phase' => null]);

    expect(sweepForStalls()['raised'])->toBe(1);
    expect(FulfillmentAlarm::query()->sole()->context['phase'] ?? null)->toBe('none');
});

test('a job the poller is not reading is not stalled', function (callable $arrange) {
    cadenceTable(coins: 60);
    $job = placedJobLastRead(90);
    $arrange($job);

    expect(sweepForStalls()['raised'])->toBe(0);
    expect(FulfillmentAlarm::query()->where('kind', FulfillmentAlarmKind::Stalled->value)->count())->toBe(0);
})->with([
    // The reconciler's "stop polling this" marker. Without this check the
    // alarm would open on a job nothing is allowed to read, and no observation
    // could ever arrive to close it again.
    'one the reconciler stopped polling' => [
        fn (FulfillmentJob $job) => $job->forceFill(['next_poll_at' => null])->save(),
    ],
    'one whose order an admin closed' => [
        fn (FulfillmentJob $job) => Order::query()
            ->whereKey($job->orderItem->order_id)
            ->update(['status' => OrderStatus::Cancelled->value]),
    ],
    'a finished job' => [
        fn (FulfillmentJob $job) => $job->forceFill(['status' => FulfillmentStatus::Completed])->save(),
    ],
    'one no supplier was given a reference for' => [
        fn (FulfillmentJob $job) => $job->forceFill(['supplier_order_id' => null])->save(),
    ],
]);

test('a job never read at all is measured from its placement, not from its row', function () {
    cadenceTable(coins: 60);

    // The row is two hours old either way; only the placement differs, and a
    // job row can predate the placement that binds a supplier to it.
    $fresh = placedJobLastRead(null, ['created_at' => now()->subMinutes(120)], 'AUT-STALL-FRESH');
    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $fresh->id,
        'placed_at' => now()->subMinutes(10),
    ]);

    $old = placedJobLastRead(null, ['created_at' => now()->subMinutes(120)], 'AUT-STALL-OLD');
    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $old->id,
        'placed_at' => now()->subMinutes(120),
    ]);

    expect(sweepForStalls())->toMatchArray(['raised' => 1, 'open' => 1]);

    $alarm = FulfillmentAlarm::query()->sole();
    expect($alarm->order_item_id)->toBe($old->order_item_id);

    // Present and null, not absent: there is no age to report for a job that
    // was never read, and the mail must not print one.
    expect(array_key_exists('quiet_minutes', (array) $alarm->context))->toBeTrue();
    expect($alarm->context['quiet_minutes'])->toBeNull();
});

test('a job never read and never placed is left alone', function () {
    // No placement row means no honest instant to measure an age from, and a
    // guessed one is how an alarm starts crying wolf.
    cadenceTable(coins: 60);
    placedJobLastRead(null, ['created_at' => now()->subMinutes(600)]);

    expect(sweepForStalls()['raised'])->toBe(0);
});

test('a stall and an unanswered read are separate alarms on one item', function () {
    // The two conditions overlap - a supplier that stopped answering also
    // stops advancing observed_at - and they are deliberately not merged: one
    // says reads are failing, the other says reads are not arriving, and an
    // operator fixes those differently.
    cadenceTable(coins: 60);
    $job = placedJobLastRead(90);
    $job->forceFill(['poll_failure_count' => 6])->save();

    expect(sweepForStalls())->toMatchArray(['raised' => 2, 'open' => 2]);

    expect(FulfillmentAlarm::query()->pluck('kind')->map->value->sort()->values()->all())
        ->toBe(['silent', 'stalled']);
});
