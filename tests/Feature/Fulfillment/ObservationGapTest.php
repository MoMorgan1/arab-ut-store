<?php

use App\Actions\Fulfillment\ApplySupplierObservation;
use App\Actions\Fulfillment\PruneObservationGaps;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentObservationGap;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Suppliers\Translation\TranslatedState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
        ->and($gap->state_changed)->toBeTrue();
});

test('a reading that told us nothing new is recorded, and marked as not news', function () {
    // Both scopes matter to whoever writes the cadence table: this row belongs
    // in "every reading" and must stay out of "readings that moved".
    [, , $job] = gapContext(['observed_at' => null, 'observed_state' => null]);

    observeWith($job, 'entered', CarbonImmutable::parse('2026-09-17 10:00:00'));
    observeWith($job->fresh(), 'entered', CarbonImmutable::parse('2026-09-17 10:03:00'));

    $gap = FulfillmentObservationGap::query()->sole();

    expect($gap->gap_seconds)->toBe(180)
        ->and($gap->state_changed)->toBeFalse();
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
        FulfillmentObservationGap::query()->create([
            'fulfillment_job_id' => $job->id,
            'delivery_phase' => DeliveryPhase::Coins,
            'supplier' => Supplier::Fft,
            'gap_seconds' => 180,
            'state_changed' => false,
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
            'public_id' => (string) str()->ulid(),
            'fulfillment_job_id' => $job->id,
            'delivery_phase' => DeliveryPhase::Coins->value,
            'supplier' => Supplier::Fft->value,
            'gap_seconds' => 180,
            'state_changed' => false,
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

test('deleting a job takes its measurements with it', function () {
    [, , $job] = gapContext(['observed_at' => null, 'observed_state' => null]);

    observeWith($job, 'entered', CarbonImmutable::parse('2026-09-17 10:00:00'));
    observeWith($job->fresh(), 'working', CarbonImmutable::parse('2026-09-17 10:03:00'));

    expect(FulfillmentObservationGap::query()->count())->toBe(1);

    $job->fresh()->delete();

    expect(FulfillmentObservationGap::query()->count())->toBe(0);
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

test('the distribution command says so plainly when there is nothing to distribute', function () {
    expect(gapCommandOutput())->toContain('No observation gaps recorded');
});

test('the distribution command reports a percentile per phase and per scope', function () {
    [, , $job] = gapContext();

    foreach ([60, 90, 120, 150, 180, 210, 240, 270, 300, 900] as $index => $seconds) {
        FulfillmentObservationGap::query()->create([
            'fulfillment_job_id' => $job->id,
            'delivery_phase' => DeliveryPhase::Coins,
            'supplier' => Supplier::Fft,
            'gap_seconds' => $seconds,
            'state_changed' => $index === 9,
            'observed_at' => now()->subHours($index + 1),
        ]);
    }

    $output = gapCommandOutput();

    expect($output)->toContain('10 gap(s)')
        ->toContain('every reading')
        ->toContain('readings that moved')
        // Nearest rank over the ten: p50 is the fifth value.
        ->toContain('180s (3m)')
        // The one reading that moved is the 900-second one, so every
        // percentile of that scope is that single sample.
        ->toContain('900s (15m)');
});

test('a phase with no samples is left off the table rather than printed as zero', function () {
    [, , $job] = gapContext();

    FulfillmentObservationGap::query()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier' => Supplier::Fft,
        'gap_seconds' => 180,
        'state_changed' => false,
        'observed_at' => now()->subHour(),
    ]);

    $output = gapCommandOutput();

    expect($output)->toContain('coins')
        ->not->toContain('challenge');
});

test('the distribution command warns while no cadence threshold is armed', function () {
    [, , $job] = gapContext();

    FulfillmentObservationGap::query()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier' => Supplier::Fft,
        'gap_seconds' => 180,
        'state_changed' => false,
        'observed_at' => now()->subHour(),
    ]);

    expect(gapCommandOutput())->toContain('still entirely unset');

    config()->set('services.suppliers.alarm.stalled_after_minutes', ['coins' => 45]);

    expect(gapCommandOutput())->not->toContain('still entirely unset');
});
