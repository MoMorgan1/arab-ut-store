<?php

use App\Actions\Fulfillment\ApplySupplierObservation;
use App\Actions\Fulfillment\ObserveFulfillmentJob;
use App\Actions\Fulfillment\PruneObservationGaps;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\PollBand;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentObservationGap;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Suppliers\Translation\TranslatedState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * An order, item and live coins job, ready to be observed.
 *
 * @param  array<string, mixed>  $jobAttributes
 * @return array{0: Order, 1: OrderItem, 2: FulfillmentJob}
 */
function gapContext(array $jobAttributes = []): array
{
    $order = Order::factory()->for(User::factory())->create([
        'order_number' => 'UT-'.fake()->unique()->numerify('########'),
        'status' => OrderStatus::InProgress,
        'placed_at' => now(),
    ]);

    $item = OrderItem::factory()->for($order)->create([
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::InProgress,
    ]);

    $job = FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'fft-gap-'.fake()->unique()->numerify('#####'),
        'delivery_phase' => DeliveryPhase::Coins,
        ...$jobAttributes,
    ]);

    return [$order, $item, $job];
}

function observeWith(FulfillmentJob $job, string $observedState, CarbonImmutable $observedAt): void
{
    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: new TranslatedState(
            status: OrderStatus::InProgress,
            holdReason: null,
            allowedActions: [],
            supported: true,
            observedState: $observedState,
        ),
        observedAt: $observedAt,
        rawPayload: ['status' => $observedState],
    );
}

afterEach(function (): void {
    Carbon::setTestNow();
});

test('a job first observation has nothing to measure from and records no gap', function () {
    [, , $job] = gapContext(['observed_at' => null, 'observed_state' => null]);

    observeWith($job, 'entered', CarbonImmutable::parse('2026-09-17 10:00:00'));

    expect($job->fresh()->observed_at?->toDateTimeString())->toBe('2026-09-17 10:00:00');
    expect(FulfillmentObservationGap::query()->count())->toBe(0);
});

test('the second observation records the interval, its phase and its supplier', function () {
    [, , $job] = gapContext(['observed_at' => null, 'observed_state' => null]);

    observeWith($job, 'entered', CarbonImmutable::parse('2026-09-17 10:00:00'));
    observeWith($job->fresh(), 'working', CarbonImmutable::parse('2026-09-17 10:03:20'));

    $gap = FulfillmentObservationGap::query()->sole();

    expect($gap->gap_seconds)->toBe(200)
        ->and($gap->fulfillment_job_id)->toBe($job->id)
        ->and($gap->delivery_phase)->toBe(DeliveryPhase::Coins)
        ->and($gap->supplier)->toBe(Supplier::Fft)
        ->and($gap->observed_at->toDateTimeString())->toBe('2026-09-17 10:03:20')
        ->and($gap->moved)->toBeTrue();
});

test('a reading that told us nothing new is recorded, and marked as not news', function () {
    // Both scopes matter to whoever writes the cadence table: this row belongs
    // in "every reading" and must stay out of "readings that moved".
    [, , $job] = gapContext(['observed_at' => null, 'observed_state' => null]);

    observeWith($job, 'entered', CarbonImmutable::parse('2026-09-17 10:00:00'));
    observeWith($job->fresh(), 'entered', CarbonImmutable::parse('2026-09-17 10:03:00'));

    $gap = FulfillmentObservationGap::query()->sole();

    expect($gap->gap_seconds)->toBe(180)
        ->and($gap->moved)->toBeFalse();
});

test('an answer we could not read neither advances the clock nor records a gap', function () {
    // The defect this closes: the supplier answers every poll with a status
    // the translator does not recognise. Those are successful reads, so the
    // failure counter never moves and the silence alarm cannot fire; if they
    // also refreshed observed_at, the stall alarm could not fire either, and
    // the gap table would fill with rows asserting a healthy cadence for a job
    // nobody can read.
    [, , $job] = gapContext([
        'observed_at' => CarbonImmutable::parse('2026-09-17 10:00:00'),
        'observed_state' => 'entered',
    ]);

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: new TranslatedState(
            status: OrderStatus::InProgress,
            holdReason: null,
            allowedActions: [],
            supported: false,
            observedState: null,
        ),
        observedAt: CarbonImmutable::parse('2026-09-17 10:03:00'),
        rawPayload: ['status' => 'somethingNobodyRecognises'],
    );

    $fresh = $job->fresh();

    expect(FulfillmentObservationGap::query()->count())->toBe(0)
        // The clock stays where the last readable answer left it, so the job
        // goes on ageing towards the stall alarm.
        ->and($fresh->observed_at?->toDateTimeString())->toBe('2026-09-17 10:00:00')
        // And the last thing we could read stays readable, rather than being
        // wiped by a null and filed as "the reading that brought news".
        ->and($fresh->observed_state)->toBe('entered')
        // It is still recorded as unreadable, because that is diagnosis.
        ->and($fresh->observation_supported)->toBeFalse();
});

test('a real challenge answer the translator cannot read leaves the clock alone', function () {
    // Same defect, driven through the translator rather than a handmade state:
    // an answer naming none of our challenge ids is one of its three
    // unsupported paths.
    Cache::flush();
    config()->set('services.suppliers.fft.base_url', 'https://fft.example.test');
    config()->set('services.suppliers.fft.api_user', 'store-fft');
    config()->set('services.suppliers.fft.api_key', 'fft-key');
    Http::preventStrayRequests();

    $challengeId = '1803b7a6-0000-0000-0000-00000064265f';

    [$order, $item, $job] = gapContext([
        'supplier' => Supplier::Fft,
        'delivery_phase' => DeliveryPhase::Challenge,
        'observed_at' => now()->subMinutes(30),
        'observed_state' => 'solvingChallenge',
    ]);

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_challenge_ids' => [$challengeId],
        'idempotency_key' => 'placement-gap-unreadable',
        'placed_at' => now()->subHours(2),
    ]);

    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response([
            $challengeId => ['sbcStatus' => 'a status nobody has heard of'],
        ]),
    ]);

    $observedAtBefore = $job->observed_at;

    app(ObserveFulfillmentJob::class)->execute($job, $item, $order);

    expect(FulfillmentObservationGap::query()->count())->toBe(0);
    expect($job->fresh()->observed_at?->toDateTimeString())->toBe($observedAtBefore?->toDateTimeString());
});

test('a failing measurement leaves the observation committed', function () {
    // The measurement must never be load-bearing for the write it measures.
    // Dropping the table is a stand-in for a deadlock or a full disk: whatever
    // the cause, the order has to keep moving.
    [, , $job] = gapContext([
        'observed_at' => CarbonImmutable::parse('2026-09-17 10:00:00'),
        'observed_state' => 'entered',
    ]);

    Schema::drop('fulfillment_observation_gaps');

    observeWith($job, 'working', CarbonImmutable::parse('2026-09-17 10:03:00'));

    $fresh = $job->fresh();

    expect($fresh->observed_at?->toDateTimeString())->toBe('2026-09-17 10:03:00')
        ->and($fresh->observed_state)->toBe('working');
});

test('an observation on a terminal order is not a sample', function () {
    // A closed order is still read once or twice on its way out, and those
    // intervals describe an order being closed rather than one being worked.
    [$order, , $job] = gapContext([
        'observed_at' => CarbonImmutable::parse('2026-09-17 10:00:00'),
        'observed_state' => 'entered',
    ]);

    $order->forceFill(['status' => OrderStatus::Completed])->save();

    observeWith($job, 'working', CarbonImmutable::parse('2026-09-17 10:03:00'));

    expect(FulfillmentObservationGap::query()->count())->toBe(0);
});

test('a reading that advances a counter under an unchanged state still counts as news', function () {
    // String inequality alone would file a shipment that delivered another
    // 50,000 coins under an unchanged status as silence.
    [, , $job] = gapContext([
        'observed_at' => CarbonImmutable::parse('2026-09-17 10:00:00'),
        'observed_state' => 'started',
        'coins_delivered' => 50_000,
    ]);

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: new TranslatedState(
            status: OrderStatus::InProgress,
            holdReason: null,
            allowedActions: [],
            supported: true,
            observedState: 'started',
            coinsDelivered: 100_000,
        ),
        observedAt: CarbonImmutable::parse('2026-09-17 10:03:00'),
        rawPayload: ['status' => 'started'],
    );

    $gap = FulfillmentObservationGap::query()->sole();

    expect($gap->moved)->toBeTrue()
        // And the counter behind that verdict is kept, so the definition of
        // news can be changed later without the history becoming unreadable.
        ->and($gap->coins_delivered)->toBe(100_000);
});

test('a reading that moves nothing at all is not news', function () {
    [, , $job] = gapContext([
        'observed_at' => CarbonImmutable::parse('2026-09-17 10:00:00'),
        'observed_state' => 'started',
        'coins_delivered' => 50_000,
    ]);

    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: new TranslatedState(
            status: OrderStatus::InProgress,
            holdReason: null,
            allowedActions: [],
            supported: true,
            observedState: 'started',
            coinsDelivered: 50_000,
        ),
        observedAt: CarbonImmutable::parse('2026-09-17 10:03:00'),
        rawPayload: ['status' => 'started'],
    );

    expect(FulfillmentObservationGap::query()->sole()->moved)->toBeFalse();
});

test('the band the poller would have used is recorded with the gap', function (bool $watched, string $expected) {
    [, , $job] = gapContext([
        'observed_at' => CarbonImmutable::parse('2026-09-17 10:00:00'),
        'observed_state' => 'entered',
        'last_viewed_at' => $watched ? CarbonImmutable::parse('2026-09-17 10:02:30') : null,
    ]);

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-17 10:03:00'));
    observeWith($job, 'working', CarbonImmutable::parse('2026-09-17 10:03:00'));

    expect(FulfillmentObservationGap::query()->sole()->band->value)->toBe($expected);
})->with([
    'a customer watching the page' => [true, 'attention'],
    'nobody watching' => [false, 'background'],
]);

test('a measurement outlives the job it measured', function () {
    // nullOnDelete, not cascade: losing a fortnight of baseline because the
    // jobs behind it were tidied up is how the table stops being able to
    // answer the question it exists for.
    [, , $job] = gapContext([
        'observed_at' => CarbonImmutable::parse('2026-09-17 10:00:00'),
        'observed_state' => 'entered',
    ]);

    observeWith($job, 'working', CarbonImmutable::parse('2026-09-17 10:03:00'));
    expect(FulfillmentObservationGap::query()->count())->toBe(1);

    $job->fresh()->delete();

    $gap = FulfillmentObservationGap::query()->sole();
    expect($gap->gap_seconds)->toBe(180)
        ->and($gap->fulfillment_job_id)->toBeNull();
});

test('a reading replayed after its own commit records no second gap', function () {
    // The caller only discards observations strictly older than the stored
    // one, so a replay of the same payload with the same fetchedAt gets all
    // the way here carrying an interval of nothing. Rollback protection does
    // not cover this: the first write committed.
    [, , $job] = gapContext(['observed_at' => null, 'observed_state' => null]);

    observeWith($job, 'entered', CarbonImmutable::parse('2026-09-17 10:00:00'));
    observeWith($job->fresh(), 'working', CarbonImmutable::parse('2026-09-17 10:03:00'));

    expect(FulfillmentObservationGap::query()->count())->toBe(1);

    observeWith($job->fresh(), 'working', CarbonImmutable::parse('2026-09-17 10:03:00'));

    // A second row here would be a zero-second gap, and one of those per
    // replay drags down every percentile the table exists to produce.
    expect(FulfillmentObservationGap::query()->count())->toBe(1);
    expect(FulfillmentObservationGap::query()->sole()->gap_seconds)->toBe(180);
});

test('an observation the lock finds stale records no gap', function () {
    // The in-memory model is deliberately behind the row, so the pre-lock fast
    // path lets this through and only the locked read refuses it. Handing in
    // an already-current model would pass with the guard deleted.
    [, , $job] = gapContext([
        'observed_at' => CarbonImmutable::parse('2026-09-17 12:03:00'),
        'observed_state' => 'initial',
    ]);

    FulfillmentJob::query()->where('id', $job->id)->update([
        'observed_at' => CarbonImmutable::parse('2026-09-17 12:05:00'),
        'observed_state' => 'concurrent_winner',
    ]);

    observeWith($job, 'stale_working', CarbonImmutable::parse('2026-09-17 12:04:00'));

    expect(FulfillmentObservationGap::query()->count())->toBe(0);
    expect($job->fresh()->observed_state)->toBe('concurrent_winner');
});

test('one observation costs one insert and no read of the table', function () {
    [, , $job] = gapContext([
        'observed_at' => CarbonImmutable::parse('2026-09-17 10:00:00'),
        'observed_state' => 'entered',
    ]);

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    observeWith($job, 'working', CarbonImmutable::parse('2026-09-17 10:03:00'));

    $touching = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_contains($sql, 'fulfillment_observation_gaps'),
    ));

    expect($touching)->toHaveCount(1);
    expect($touching[0])->toStartWith('insert into');
});

test('an observation older than the one stored is discarded and records no gap', function () {
    [, , $job] = gapContext([
        'observed_at' => CarbonImmutable::parse('2026-09-17 10:05:00'),
        'observed_state' => 'working',
    ]);

    observeWith($job, 'entered', CarbonImmutable::parse('2026-09-17 10:04:00'));

    expect(FulfillmentObservationGap::query()->count())->toBe(0);
    expect($job->fresh()->observed_at?->toDateTimeString())->toBe('2026-09-17 10:05:00');
});

test('a challenge read is filed under the supplier that answered it, not the job own', function () {
    // RecordSupplierPlacement keeps the FIRST placement's supplier on the job
    // row on purpose, so a job that funded its coins at UTT and solves
    // challenges at FFT still says "utt". Filing the FFT reading under UTT
    // would make the report's supplier filter lie.
    Cache::flush();
    config()->set('services.suppliers.fft.base_url', 'https://fft.example.test');
    config()->set('services.suppliers.fft.api_user', 'store-fft');
    config()->set('services.suppliers.fft.api_key', 'fft-key');
    Http::preventStrayRequests();

    $challengeId = '1803b7a6-0000-0000-0000-00000064265f';

    [$order, $item, $job] = gapContext([
        'supplier' => Supplier::Utt,
        'supplier_order_id' => 'utt-funded-'.fake()->unique()->numerify('#####'),
        'delivery_phase' => DeliveryPhase::Challenge,
        'observed_at' => now()->subMinutes(5),
        'observed_state' => 'entered',
    ]);

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'fft-sbc-'.fake()->unique()->numerify('#####'),
        'supplier_challenge_ids' => [$challengeId],
        'idempotency_key' => 'placement-gap-cross-supplier',
        'placed_at' => now()->subMinutes(10),
    ]);

    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response([
            $challengeId => [
                'sbcStatus' => 'solvingChallenge',
                'challengesDone' => 3,
                'totalChallenges' => 7,
                'timesSolved' => 1,
                'timesToSolve' => 2,
            ],
        ]),
    ]);

    app(ObserveFulfillmentJob::class)->execute($job, $item, $order);

    $gap = FulfillmentObservationGap::query()->sole();

    expect($gap->supplier)->toBe(Supplier::Fft)
        ->and($gap->delivery_phase)->toBe(DeliveryPhase::Challenge);

    // The job row is untouched by this and still advertises its funding
    // placement, which is exactly why the gap could not be read off it.
    expect($job->fresh()->supplier)->toBe(Supplier::Utt);
});

test('the gap is filed under the phase the reading put the job in', function () {
    [, , $job] = gapContext([
        'observed_at' => CarbonImmutable::parse('2026-09-17 10:00:00'),
        'observed_state' => 'entered',
        'delivery_phase' => DeliveryPhase::Coins,
    ]);

    // A payload carrying challenges moves the job into the challenge phase, and
    // the gap belongs to the phase it is now in - not to the one it left.
    app(ApplySupplierObservation::class)->execute(
        job: $job,
        state: new TranslatedState(
            status: OrderStatus::InProgress,
            holdReason: null,
            allowedActions: [],
            supported: true,
            observedState: 'solving',
            squadsDone: 1,
            squadsTotal: 4,
        ),
        observedAt: CarbonImmutable::parse('2026-09-17 10:01:00'),
        rawPayload: ['status' => 'solving'],
    );

    expect(FulfillmentObservationGap::query()->sole()->delivery_phase)->toBe(DeliveryPhase::Challenge);
});

test('the poller records a gap across two ticks, and a failed read records nothing', function () {
    Cache::flush();
    config()->set('services.suppliers.fft.base_url', 'https://fft.example.test');
    config()->set('services.suppliers.fft.api_user', 'store-fft');
    config()->set('services.suppliers.fft.api_key', 'fft-key');
    Http::preventStrayRequests();

    [, , $job] = gapContext([
        'observed_at' => null,
        'observed_state' => null,
        'next_poll_at' => now()->subMinute(),
        'last_viewed_at' => null,
    ]);

    // One registration with a switch, because a second Http::fake() appends to
    // the stub list rather than replacing it: the first matching stub would go
    // on answering and the "failed read" half of this test would silently be
    // testing a successful one.
    $supplierIsDown = false;

    Http::fake(function (Request $request) use (&$supplierIsDown) {
        return $supplierIsDown
            ? Http::response('upstream is down', 500)
            : Http::response(['status' => 'started', 'amount' => 10, 'amountOrdered' => 50]);
    });

    $this->artisan('fulfillment:poll')->assertExitCode(0);

    // The first landed reading has no predecessor.
    expect(FulfillmentObservationGap::query()->count())->toBe(0);

    $this->travel(4)->minutes();
    $this->artisan('fulfillment:poll')->assertExitCode(0);

    $gap = FulfillmentObservationGap::query()->sole();
    expect($gap->gap_seconds)->toBe(240)
        ->and($gap->supplier)->toBe(Supplier::Fft);

    // A read that does not land never reaches the reconciler, so it leaves no
    // trace here: a gap is time between two readings, and there is no second
    // reading to measure to.
    $supplierIsDown = true;
    $job->fresh()->forceFill(['next_poll_at' => now()->subMinute()])->save();

    $this->travel(4)->minutes();
    $this->artisan('fulfillment:poll')->assertExitCode(0);

    expect(FulfillmentObservationGap::query()->count())->toBe(1);
    expect($job->fresh()->poll_failure_count)->toBeGreaterThan(0);
});

test('the prune keeps the retention window and deletes what is behind it', function () {
    config()->set('services.suppliers.poll.gap_retention_days', 14);
    [, , $job] = gapContext();

    $ages = [1, 13, 14, 15, 40];

    foreach ($ages as $days) {
        FulfillmentObservationGap::factory()->create([
            'fulfillment_job_id' => $job->id,
            'delivery_phase' => DeliveryPhase::Coins,
            'supplier' => Supplier::Fft,
            'gap_seconds' => 180,
            'moved' => false,
            'observed_at' => now()->subDays($days),
        ]);
    }

    // Fourteen days old is the boundary and is kept: the window is "the last
    // fourteen days", not "the last thirteen".
    expect(app(PruneObservationGaps::class)->execute())->toBe(2);

    $kept = FulfillmentObservationGap::query()->pluck('observed_at')
        ->map(fn ($observedAt): int => (int) round($observedAt->diffInDays(now(), true)))
        ->sort()
        ->values()
        ->all();

    expect($kept)->toBe([1, 13, 14]);
});

test('the prune clears a backlog larger than one chunk', function () {
    config()->set('services.suppliers.poll.gap_retention_days', 14);
    [, , $job] = gapContext();

    $rows = [];

    for ($i = 0; $i < 620; $i++) {
        $rows[] = [
            'fulfillment_job_id' => $job->id,
            'delivery_phase' => DeliveryPhase::Coins->value,
            'supplier' => Supplier::Fft->value,
            'band' => PollBand::Background->value,
            'gap_seconds' => 180,
            'moved' => false,
            'poll_failure_count' => 0,
            'observed_at' => now()->subDays(30),
        ];
    }

    FulfillmentObservationGap::query()->insert($rows);

    expect(app(PruneObservationGaps::class)->execute())->toBe(620);
    expect(FulfillmentObservationGap::query()->count())->toBe(0);
});

test('a prune with no retention window configured refuses rather than guessing', function () {
    config()->set('services.suppliers.poll.gap_retention_days', null);

    expect(fn () => app(PruneObservationGaps::class)->execute())
        ->toThrow(RuntimeException::class);
});

/**
 * The command's whole output.
 *
 * Read in one piece rather than through `expectsOutputToContain`, which
 * consumes one expectation per write and so silently turns a set of
 * assertions about a table into assertions about which line each one landed
 * on.
 */
function gapCommandOutput(int $days = 14): string
{
    expect(Artisan::call('fulfillment:observation-gaps', ['--days' => $days]))->toBe(0);

    return Artisan::output();
}

/**
 * One row of the printed table, by its phase and scope, as trimmed cells.
 *
 * Whole-output substring assertions pass on the wrong row: every number in the
 * moved-only scope also appears somewhere in the unrestricted one, so dropping
 * the filter altogether would not fail them.
 *
 * @return list<string>
 */
function gapRow(string $output, string $phase, string $scope): array
{
    foreach (explode("\n", $output) as $line) {
        if (! str_contains($line, '|')) {
            continue;
        }

        $cells = array_values(array_filter(
            array_map('trim', explode('|', $line)),
            static fn (string $cell): bool => $cell !== '',
        ));

        if (($cells[0] ?? null) === $phase && ($cells[2] ?? null) === $scope) {
            return $cells;
        }
    }

    return [];
}

test('the distribution command says so plainly when there is nothing to distribute', function () {
    expect(gapCommandOutput())->toContain('No observation gaps recorded');
});

test('the distribution command reports a percentile per phase and per scope', function () {
    [, , $job] = gapContext();

    // Nine readings that told us nothing and one that did, and the one that
    // did is a value no other row can produce - so an assertion on the
    // moved-only row cannot be satisfied by the unrestricted one.
    foreach ([60, 90, 120, 150, 180, 210, 240, 270, 300, 1500] as $index => $seconds) {
        FulfillmentObservationGap::factory()->create([
            'fulfillment_job_id' => $job->id,
            'delivery_phase' => DeliveryPhase::Coins,
            'supplier' => Supplier::Fft,
            'gap_seconds' => $seconds,
            'moved' => $index === 9,
            'observed_at' => now()->subHours($index + 1),
        ]);
    }

    $output = gapCommandOutput();

    expect($output)->toContain('10 gap(s)');

    // phase | scope | samples | p50 | p90 | p95 | p99 | max
    // Nearest rank over the ten: p50 is the fifth value, p90 the ninth.
    expect(gapRow($output, 'coins', 'every reading'))
        ->toBe(['coins', 'background', 'every reading', '10', '180s (3m)', '300s (5m)', '1500s (25m)', '1500s (25m)', '1500s (25m)']);

    // One sample, so every percentile of that scope is that sample.
    expect(gapRow($output, 'coins', 'the reading that brought news'))
        ->toBe(['coins', 'background', 'the reading that brought news', '1', '1500s (25m)', '1500s (25m)', '1500s (25m)', '1500s (25m)', '1500s (25m)']);
});

test('a supplier filter narrows the table to that supplier', function () {
    [, , $job] = gapContext();

    foreach ([[Supplier::Fft, 120], [Supplier::Utt, 600]] as [$supplier, $seconds]) {
        FulfillmentObservationGap::factory()->create([
            'fulfillment_job_id' => $job->id,
            'delivery_phase' => DeliveryPhase::Coins,
            'supplier' => $supplier,
            'gap_seconds' => $seconds,
            'moved' => false,
            'observed_at' => now()->subHour(),
        ]);
    }

    expect(Artisan::call('fulfillment:observation-gaps', ['--days' => 14, '--supplier' => 'utt']))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('1 gap(s)')
        ->toContain('supplier utt');
    expect(gapRow($output, 'coins', 'every reading')[4] ?? null)->toBe('600s (10m)');
});

test('a phase with no samples is left off the table rather than printed as zero', function () {
    [, , $job] = gapContext();

    FulfillmentObservationGap::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier' => Supplier::Fft,
        'gap_seconds' => 180,
        'moved' => false,
        'observed_at' => now()->subHour(),
    ]);

    $output = gapCommandOutput();

    expect($output)->toContain('coins')
        ->not->toContain('challenge');
});

test('the distribution command says which phases are still on the fallback', function () {
    [, , $job] = gapContext();

    FulfillmentObservationGap::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier' => Supplier::Fft,
        'gap_seconds' => 180,
        'moved' => false,
        'observed_at' => now()->subHour(),
    ]);

    config()->set('services.suppliers.alarm.stalled_fallback_minutes', 60);
    config()->set('services.suppliers.alarm.stalled_after_minutes', []);

    expect(gapCommandOutput())->toContain('60-minute fallback');

    // Once a measured number is in the table the notice has nothing to say.
    config()->set('services.suppliers.alarm.stalled_after_minutes', ['coins' => 45]);

    expect(gapCommandOutput())->not->toContain('fallback');
});

test('the guidance points at the column that measures what it claims to', function () {
    // The wording is the instruction for writing the cadence table later, and
    // neither scope measures how long a job sat in one state.
    [, , $job] = gapContext();

    FulfillmentObservationGap::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier' => Supplier::Fft,
        'gap_seconds' => 180,
        'moved' => true,
        'observed_at' => now()->subHour(),
    ]);

    expect(gapCommandOutput())
        ->toContain('"every reading" p99')
        ->toContain('Neither column measures how long a job sat in one state.');
});
