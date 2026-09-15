<?php

use App\Actions\Fulfillment\ObserveFulfillmentJob;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const SOLVE_A = '1803b7a6-0000-4000-8000-00000064265f';
const SOLVE_B = '1803b7a6-0000-4000-8000-000000642660';

/** @return array{FulfillmentJob, OrderItem} */
function solvingChallenge(): array
{
    $order = Order::factory()->for(User::factory())->create(['status' => OrderStatus::InProgress, 'placed_at' => now()]);
    $item = OrderItem::factory()->for($order)->create([
        'service_type' => ServiceType::Sbc,
        'status' => OrderItemStatus::InProgress,
    ]);
    $job = FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => SOLVE_A,
        'delivery_phase' => DeliveryPhase::Challenge,
        'next_poll_at' => now(),
    ]);
    FulfillmentPlacement::query()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => SOLVE_A,
        'supplier_challenge_ids' => [SOLVE_A, SOLVE_B],
        'idempotency_key' => 'fulfillment-placement:'.$item->public_id.':challenge',
        'placed_at' => now(),
    ]);

    return [$job, $item];
}

/**
 * What FFT answers next. Http::fake keeps the first stub registered for a
 * URL, so the stubs are registered once (beforeEach) and read these.
 */
final class FftSolveAnswers
{
    /** @var array<string, string> challenge id => sbcStatus */
    public static array $statuses = [];

    /** @var list<array{int, mixed}> queued retrySBCAPI answers as [status, body]; empty means 200 ok */
    public static array $retries = [];
}

/** @param  array<string, string>  $statuses  challenge id => sbcStatus */
function fftSolveStatuses(array $statuses): void
{
    FftSolveAnswers::$statuses = $statuses;
}

function readSolve(FulfillmentJob $job, OrderItem $item): void
{
    app(ObserveFulfillmentJob::class)->execute($job->fresh(), $item->fresh(), $item->order);
}

function retriesSent(): array
{
    $sent = [];
    Http::assertSent(function (Request $request) use (&$sent): bool {
        if ($request->url() === 'https://fft.example.test/retrySBCAPI') {
            $sent[] = $request->data()['sbcSolveID'];
        }

        return true;
    });

    return $sent;
}

beforeEach(function (): void {
    Cache::flush();
    config()->set('services.suppliers.fft.base_url', 'https://fft.example.test');
    config()->set('services.suppliers.fft.api_user', 'store-fft');
    config()->set('services.suppliers.fft.api_key', 'fft-key');
    FftSolveAnswers::$statuses = [];
    FftSolveAnswers::$retries = [];
    Http::preventStrayRequests();
    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => fn () => Http::response(array_map(
            fn (string $status): array => ['sbcStatus' => $status, 'challengesDone' => 1, 'totalChallenges' => 3, 'timesSolved' => 0, 'timesToSolve' => 1],
            FftSolveAnswers::$statuses,
        )),
        'https://fft.example.test/retrySBCAPI' => function () {
            $next = array_shift(FftSolveAnswers::$retries);

            return $next === null ? Http::response(['status' => 'ok']) : Http::response($next[1], $next[0]);
        },
    ]);
});

test('a transient solve status is retried on v14 cadence: the second read, then every fourth, four times at most', function (): void {
    [$job, $item] = solvingChallenge();
    fftSolveStatuses([SOLVE_A => 'sessionExpired', SOLVE_B => 'solvingChallenge']);

    $retriesAfterRead = [];

    for ($read = 1; $read <= 16; $read++) {
        readSolve($job, $item);
        $retriesAfterRead[$read] = count(retriesSent());
    }

    // Reads 2, 6, 10 and 14 send a retry; nothing after the fourth.
    expect($retriesAfterRead)->toBe([1 => 0, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 2, 7 => 2, 8 => 2, 9 => 2, 10 => 3, 11 => 3, 12 => 3, 13 => 3, 14 => 4, 15 => 4, 16 => 4])
        ->and(retriesSent())->each->toBe([SOLVE_A])
        ->and($job->fresh()->challenge_retries)->toBe([SOLVE_A => ['status' => 'sessionExpired', 'reads' => 16, 'retries' => 4]]);
});

test('a status that needs a person is never retried on its own, and a recovered challenge gets a fresh budget', function (): void {
    [$job, $item] = solvingChallenge();

    // v14 left these to a human: wrong credentials, no coins, a console still signed in.
    fftSolveStatuses([SOLVE_A => 'WrongUserPass', SOLVE_B => 'noFunds']);
    readSolve($job, $item);
    readSolve($job, $item);
    expect(retriesSent())->toBe([])
        ->and($job->fresh()->challenge_retries)->toBeNull();

    // Transient twice: one retry on the second read.
    fftSolveStatuses([SOLVE_A => 'clickFailed', SOLVE_B => 'solvingChallenge']);
    readSolve($job, $item);
    readSolve($job, $item);
    expect(retriesSent())->toBe([[SOLVE_A]])
        ->and($job->fresh()->challenge_retries[SOLVE_A]['reads'])->toBe(2);

    // It recovers: the count is cleared, so a later stall starts over.
    fftSolveStatuses([SOLVE_A => 'solvingChallenge', SOLVE_B => 'solvingChallenge']);
    readSolve($job, $item);
    expect($job->fresh()->challenge_retries)->toBeNull();

    fftSolveStatuses([SOLVE_A => 'noSolutionFound', SOLVE_B => 'LoginFailed']);
    readSolve($job, $item);
    readSolve($job, $item);
    // The recorded requests accumulate over the test: one from the clickFailed stall, two now.
    expect(retriesSent())->toBe([[SOLVE_A], [SOLVE_A], [SOLVE_B]])
        ->and(array_keys($job->fresh()->challenge_retries))->toBe([SOLVE_A, SOLVE_B]);
});

test('a retry the supplier cannot take is counted as a read, not as a retry, and the next due read tries again', function (): void {
    [$job, $item] = solvingChallenge();
    fftSolveStatuses([SOLVE_A => 'sessionExpired', SOLVE_B => 'finished']);
    FftSolveAnswers::$retries = [[503, '']];

    for ($read = 1; $read <= 6; $read++) {
        readSolve($job, $item);
    }

    // Read 2 failed at the supplier, read 6 succeeded: one retry on the record.
    expect(count(retriesSent()))->toBe(2)
        ->and($job->fresh()->challenge_retries[SOLVE_A])->toBe(['status' => 'sessionExpired', 'reads' => 6, 'retries' => 1]);
});
