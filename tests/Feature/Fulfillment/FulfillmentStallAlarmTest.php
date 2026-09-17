<?php

use App\Actions\Fulfillment\AlertOwnerOfFulfillmentSilence;
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
use App\Notifications\FulfillmentSilenceAlert;
use App\Suppliers\SupplierGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

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
        // Due. A job whose next poll is still ahead of it is waiting its turn,
        // not being neglected, and the sweep says so - so a fixture that left
        // the factory's future next_poll_at in place would test nothing.
        'next_poll_at' => now()->subMinute(),
    ], $attributes));
}

/** @return list<string> The kinds currently open, sorted. */
function openAlarmKinds(): array
{
    return FulfillmentAlarm::query()
        ->whereNull('resolved_at')
        ->pluck('kind')
        ->map(fn (FulfillmentAlarmKind $kind): string => $kind->value)
        ->sort()
        ->values()
        ->all();
}

/**
 * The per-phase overrides. Omitting a phase leaves it on the fallback, which
 * is the shipped state; naming one with null switches that phase off.
 *
 * @param  array<string, int|null>  $byPhase
 */
function cadenceOverrides(array $byPhase): void
{
    config()->set('services.suppliers.alarm.stalled_after_minutes', $byPhase);
}

beforeEach(function (): void {
    // The array cache carries a circuit opened by one test into the next.
    Cache::flush();

    // The shipped configuration, stated rather than assumed: a sixty-minute
    // fallback and no measured per-phase number yet.
    config()->set('services.suppliers.alarm.stalled_fallback_minutes', [
        'attention' => 30,
        'background' => 60,
    ]);
    config()->set('services.suppliers.alarm.stalled_after_minutes', []);
});

test('a phase with no entry of its own is watched by the fallback', function () {
    placedJobLastRead(90);

    expect(sweepForStalls())->toMatchArray(['raised' => 1, 'resolved' => 0, 'open' => 1]);
    expect(FulfillmentAlarm::query()->sole()->kind)->toBe(FulfillmentAlarmKind::Stalled);
});

test('a job inside its threshold raises nothing', function () {
    placedJobLastRead(10);

    expect(sweepForStalls()['raised'])->toBe(0);
    expect(FulfillmentAlarm::query()->count())->toBe(0);
});

test('a phase named with a null is not watched, and the others still are', function () {
    // The off switch has to survive the fallback: an explicit null is an
    // answer, and the fallback must not overrule it.
    cadenceOverrides(['coins' => null]);

    placedJobLastRead(90, [], 'AUT-STALL-COINS');
    placedJobLastRead(90, ['delivery_phase' => DeliveryPhase::Challenge], 'AUT-STALL-SBC');

    expect(sweepForStalls())->toMatchArray(['raised' => 1, 'open' => 1]);
    expect(FulfillmentAlarm::query()->sole()->context['phase'] ?? null)->toBe('challenge');
});

test('a per-phase number overrides the fallback in both directions', function (int $minutes, int $readMinutesAgo, int $expected) {
    cadenceOverrides(['coins' => $minutes]);
    placedJobLastRead($readMinutesAgo);

    expect(sweepForStalls()['raised'])->toBe($expected);
})->with([
    'looser than the fallback, and the job is inside it' => [240, 90, 0],
    'looser than the fallback, and the job is past it' => [240, 300, 1],
    'tighter than the fallback, and the job is past it' => [15, 30, 1],
    'tighter than the fallback, and the job is inside it' => [15, 5, 0],
]);

test('a fallback that is not a positive number watches nothing at all', function (mixed $value) {
    config()->set('services.suppliers.alarm.stalled_fallback_minutes', ['attention' => $value, 'background' => $value]);
    placedJobLastRead(90);

    expect(sweepForStalls()['raised'])->toBe(0);
    expect(FulfillmentAlarm::query()->count())->toBe(0);
})->with([
    'null' => [null],
    'zero' => [0],
    'negative' => [-60],
    'a word where a number belongs' => ['soon'],
    'an empty string' => [''],
]);

test('a per-phase entry that is not a positive number switches that phase off', function (mixed $value) {
    cadenceOverrides(['coins' => $value]);
    placedJobLastRead(90);

    expect(sweepForStalls()['raised'])->toBe(0);
})->with([
    'null' => [null],
    'zero' => [0],
    'a word where a number belongs' => ['soon'],
]);

test('a fallback that is not a table watches nothing at all', function () {
    config()->set('services.suppliers.alarm.stalled_fallback_minutes', 60);
    placedJobLastRead(90);

    expect(sweepForStalls()['raised'])->toBe(0);
});

test('a table that is not a table leaves every phase on the fallback', function () {
    config()->set('services.suppliers.alarm.stalled_after_minutes', null);
    placedJobLastRead(90);

    expect(sweepForStalls()['raised'])->toBe(1);
});

test('the band decides the threshold, not the phase alone', function (bool $watched, int $readMinutesAgo, int $expected) {
    // Thirty minutes on the attention cadence is seventy-two missed reads;
    // sixty on the background one is twenty. One number cannot serve both.
    placedJobLastRead($readMinutesAgo, [
        'last_viewed_at' => $watched ? now()->subSeconds(60) : null,
    ]);

    expect(sweepForStalls()['raised'])->toBe($expected);
    if ($expected === 1) {
        expect(FulfillmentAlarm::query()->sole()->context['band'] ?? null)
            ->toBe($watched ? 'attention' : 'background');
    }
})->with([
    'watched, past the attention threshold' => [true, 40, 1],
    'watched, inside the attention threshold' => [true, 20, 0],
    'unwatched, past the attention threshold but inside its own' => [false, 40, 0],
    'unwatched, past its own threshold' => [false, 90, 1],
]);

test('a job the poller is deliberately not asking about yet is not stalled', function (callable $arrange) {
    // deferForCircuit pushes next_poll_at into the future without touching the
    // failure counter, so a rate-limited supplier would otherwise raise a stall
    // that can never hand over to the silence alarm - and an operator would go
    // looking at a supplier that is behaving.
    $job = placedJobLastRead(90);
    $arrange($job);

    expect(sweepForStalls()['raised'])->toBe(0);
})->with([
    'one deferred behind a circuit cooldown' => [
        fn (FulfillmentJob $job) => $job->forceFill(['next_poll_at' => now()->addMinute()])->save(),
    ],
    'one another tick is reading right now' => [
        fn (FulfillmentJob $job) => $job->forceFill(['leased_until' => now()->addSeconds(30)])->save(),
    ],
]);

test('a stall behind an open circuit says so rather than blaming the supplier', function () {
    $job = placedJobLastRead(90, ['supplier' => Supplier::Fft, 'supplier_order_id' => 'FFT-CIRCUIT']);

    // A supplier telling us to back off opens the circuit immediately.
    app(SupplierGuard::class)->recordFailure($job->supplier, '30');

    expect(sweepForStalls()['raised'])->toBe(1);
    expect(FulfillmentAlarm::query()->sole()->context['circuit_open'] ?? null)->toBeTrue();
});

test('a quiet supplier is not reported as a stopped one', function () {
    placedJobLastRead(90);

    expect(sweepForStalls()['raised'])->toBe(1);
    expect(FulfillmentAlarm::query()->sole()->context['circuit_open'] ?? null)->toBeFalse();
});

test('the first sweep after a deploy does not open an alarm for everything ancient', function () {
    // The alarm starts watching when it ships. Without the window, the store's
    // whole back catalogue of quiet jobs arrives in one mail.
    config()->set('services.suppliers.alarm.raise_window_hours', 48);

    placedJobLastRead(90, [], 'AUT-STALL-RECENT');
    placedJobLastRead(72 * 60, [], 'AUT-STALL-ANCIENT');

    expect(sweepForStalls())->toMatchArray(['raised' => 1, 'open' => 1]);
    expect(FulfillmentAlarm::query()->sole()->context['order_number'] ?? null)->toBe('AUT-STALL-RECENT');
});

test('an alarm already open stays open however old the silence gets', function () {
    config()->set('services.suppliers.alarm.raise_window_hours', 48);
    $job = placedJobLastRead(90);

    expect(sweepForStalls()['raised'])->toBe(1);

    // Four days later it is far outside the window that was allowed to open
    // it. Resolution reads the unwindowed set, so it must still be open.
    $job->fresh()->forceFill(['observed_at' => now()->subDays(5)])->save();

    expect(sweepForStalls())->toMatchArray(['raised' => 0, 'resolved' => 0, 'open' => 1]);
});

test('a job past its threshold raises once and not again', function () {
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
    $job = placedJobLastRead(90);
    sweepForStalls();

    // Exactly what one successful read does to the row.
    $job->forceFill(['observed_at' => now()])->save();

    expect(sweepForStalls())->toMatchArray(['raised' => 0, 'resolved' => 1, 'open' => 0]);
    expect(FulfillmentAlarm::query()->sole()->resolved_at)->not->toBeNull();
});

test('the alarm resolves when its phase is switched off', function () {
    placedJobLastRead(90);
    expect(sweepForStalls()['raised'])->toBe(1);

    // Turning a phase off has to close what it opened. An alarm nothing is
    // still detecting is an alarm nobody can clear.
    cadenceOverrides(['coins' => null]);

    expect(sweepForStalls())->toMatchArray(['raised' => 0, 'resolved' => 1, 'open' => 0]);
});

test('the alarm resolves when the job stops being one the poller reads', function (callable $arrange) {
    $job = placedJobLastRead(90);
    expect(sweepForStalls()['raised'])->toBe(1);

    $arrange($job->fresh());

    expect(sweepForStalls())->toMatchArray(['raised' => 0, 'resolved' => 1, 'open' => 0]);
})->with([
    'the reconciler stopped polling it' => [
        fn (FulfillmentJob $job) => $job->forceFill(['next_poll_at' => null])->save(),
    ],
    'an admin closed its order' => [
        fn (FulfillmentJob $job) => Order::query()
            ->whereKey($job->orderItem->order_id)
            ->update(['status' => OrderStatus::Cancelled->value]),
    ],
    'the job finished' => [
        fn (FulfillmentJob $job) => $job->forceFill(['status' => FulfillmentStatus::Completed])->save(),
    ],
]);

test('a job carrying no phase is watched, and says so', function () {
    placedJobLastRead(90, ['delivery_phase' => null]);

    expect(sweepForStalls()['raised'])->toBe(1);
    expect(FulfillmentAlarm::query()->sole()->context['phase'] ?? null)->toBe('none');
});

test('the none key switches off the phaseless jobs without touching the rest', function () {
    cadenceOverrides(['none' => null]);

    placedJobLastRead(90, ['delivery_phase' => null], 'AUT-STALL-NONE');
    placedJobLastRead(90, [], 'AUT-STALL-COINS');

    expect(sweepForStalls())->toMatchArray(['raised' => 1, 'open' => 1]);
    expect(FulfillmentAlarm::query()->sole()->context['phase'] ?? null)->toBe('coins');
});

test('a job the poller is not reading is not stalled', function (callable $arrange) {
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
    placedJobLastRead(null, ['created_at' => now()->subMinutes(600)]);

    expect(sweepForStalls()['raised'])->toBe(0);
});

test('a stalled job hands over to the silent alarm at the failure count, and takes it back on recovery', function () {
    // The two conditions overlap - reads that keep failing also stop advancing
    // observed_at - and an item under both would be counted twice on the panel
    // and described two ways in one mail. So the boundary is exclusive and the
    // handover is a resolve plus a raise, not two open alarms.
    $job = placedJobLastRead(90, ['poll_failure_count' => 5]);

    expect(sweepForStalls())->toMatchArray(['raised' => 1, 'resolved' => 0, 'open' => 1]);
    expect(openAlarmKinds())->toBe(['stalled']);

    // One more fruitless read crosses into the silent alarm's territory.
    $job->fresh()->forceFill(['poll_failure_count' => 6])->save();

    expect(sweepForStalls())->toMatchArray(['raised' => 1, 'resolved' => 1, 'open' => 1]);
    expect(openAlarmKinds())->toBe(['silent']);

    // A read landing resets the counter and the clock, and closes both.
    $job->fresh()->forceFill(['poll_failure_count' => 0, 'observed_at' => now()])->save();

    expect(sweepForStalls())->toMatchArray(['raised' => 0, 'resolved' => 1, 'open' => 0]);
    expect(openAlarmKinds())->toBe([]);
});

test('the mail carries the numbers that make a stall triageable', function () {
    config()->set('store.order_alerts.email', 'owner@example.test');
    Notification::fake();

    placedJobLastRead(90, ['delivery_phase' => DeliveryPhase::Challenge]);
    sweepForStalls();

    expect(app(AlertOwnerOfFulfillmentSilence::class)->execute())->toBe(1);

    Notification::assertSentOnDemand(
        FulfillmentSilenceAlert::class,
        function (FulfillmentSilenceAlert $notification): bool {
            $detail = $notification->rows[0]['detail'] ?? '';

            // How long, which phase, which supplier. Without the first of
            // those the owner reads "no new reading for a while" and has to go
            // and find out the one number the sweep already knew.
            expect($notification->rows[0]['kind'] ?? null)->toBe('stalled')
                ->and($detail)->toContain('90')
                ->and($detail)->toContain('challenge')
                ->and($detail)->toContain('utt');

            return true;
        },
    );
});

test('a job at the failure count is the silent alarm alone, never both', function () {
    placedJobLastRead(90, ['poll_failure_count' => 9]);

    expect(sweepForStalls())->toMatchArray(['raised' => 1, 'open' => 1]);
    expect(openAlarmKinds())->toBe(['silent']);
});
