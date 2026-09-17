<?php

use App\Actions\Fulfillment\AlertOwnerOfFulfillmentSilence;
use App\Actions\Fulfillment\SweepFulfillmentAlarms;
use App\Admin\Queries\ReadQueueHealth;
use App\Enums\FulfillmentAlarmKind;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\FulfillmentAlarm;
use App\Models\FulfillmentJob;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Notifications\FulfillmentSilenceAlert;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** @return array{raised: int, resolved: int, open: int} */
function sweepAlarms(): array
{
    return app(SweepFulfillmentAlarms::class)->execute();
}

function alertSilence(): int
{
    return app(AlertOwnerOfFulfillmentSilence::class)->execute();
}

/** An order paid a given number of minutes ago. */
function orderPaidMinutesAgo(int $minutes, string $number = 'AUT-SILENT-1'): Order
{
    $order = paidOrder($number);
    $order->forceFill(['paid_at' => now()->subMinutes($minutes)])->save();

    return $order;
}

function boosterItem(Order $order): OrderItem
{
    $variant = ProductVariant::factory()->create([
        'service_type' => ServiceType::Rivals,
        'platform' => Platform::PlayStation,
    ]);

    return OrderItem::factory()->for($order)->create([
        'product_variant_id' => $variant->id,
        'service_type' => ServiceType::Rivals,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::Received,
    ]);
}

/**
 * The outbox row a paid order leaves behind, carrying the reason the last
 * publisher run could not send it. Written the way `SignedOutboxDelivery`
 * releases a row: back to pending, with the reason in `last_error`.
 */
function undeliveredPlacement(Order $order, ?string $reason): IntegrationEvent
{
    return IntegrationEvent::query()->create([
        'event_id' => (string) Str::ulid(),
        'event_type' => 'order.paid',
        'aggregate_type' => 'order',
        'aggregate_id' => $order->public_id,
        'schema_version' => 2,
        'payload' => ['order_public_id' => $order->public_id, 'order_number' => $order->order_number],
        'status' => 'pending',
        'idempotency_key' => 'order-paid:'.$order->id,
        'attempts' => 4,
        'available_at' => now(),
        'last_error' => $reason,
    ]);
}

/** A job a supplier is working on, with a reading of a given age. */
function placedJob(OrderItem $item, ?int $observedMinutesAgo, array $attributes = []): FulfillmentJob
{
    $job = FulfillmentJob::factory()->live()->create(array_replace([
        'order_item_id' => $item->id,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'FFT-9001',
        'poll_failure_count' => 0,
        'observed_at' => $observedMinutesAgo === null ? null : now()->subMinutes($observedMinutesAgo),
    ], $attributes));

    return $job;
}

beforeEach(function (): void {
    config()->set('store.order_alerts.email', 'owner@example.test');
    Notification::fake();
});

test('a paid automated item nobody placed raises an alarm and mails the owner once', function () {
    $order = orderPaidMinutesAgo(20);
    $item = coinsItem($order);

    expect(sweepAlarms())->toMatchArray(['raised' => 1, 'resolved' => 0, 'open' => 1]);

    $alarm = FulfillmentAlarm::query()->sole();
    expect($alarm->order_item_id)->toBe($item->id)
        ->and($alarm->kind)->toBe(FulfillmentAlarmKind::Unplaced)
        ->and($alarm->notified_at)->toBeNull()
        ->and($alarm->context['order_number'] ?? null)->toBe('AUT-SILENT-1');

    expect(alertSilence())->toBe(1);
    Notification::assertSentOnDemand(FulfillmentSilenceAlert::class);
    expect($alarm->refresh()->notified_at)->not->toBeNull();

    // The second sweep finds the same silence and must not mail about it again.
    Notification::fake();
    expect(sweepAlarms())->toMatchArray(['raised' => 0, 'resolved' => 0, 'open' => 1]);
    expect(alertSilence())->toBe(0);
    Notification::assertNothingSent();
});

test('an item still inside the wait is not an alarm', function () {
    $order = orderPaidMinutesAgo(5);
    coinsItem($order);

    expect(sweepAlarms()['raised'])->toBe(0);
    expect(FulfillmentAlarm::query()->count())->toBe(0);
});

test('an order older than the raise window never opens a new alarm', function () {
    $order = orderPaidMinutesAgo(72 * 60);
    coinsItem($order);

    expect(sweepAlarms()['raised'])->toBe(0);
    expect(FulfillmentAlarm::query()->count())->toBe(0);
});

test('an alarm already open stays open however old the order gets', function () {
    $order = orderPaidMinutesAgo(20);
    coinsItem($order);
    sweepAlarms();

    // Four days later the order is far outside the raise window; the alarm it
    // already earned must not quietly disappear from the panel.
    $this->travel(4)->days();

    expect(sweepAlarms())->toMatchArray(['raised' => 0, 'resolved' => 0, 'open' => 1]);
});

test('manual services, closed items and placed items are never alarms', function (callable $arrange) {
    $order = orderPaidMinutesAgo(20);
    $arrange($order);

    expect(sweepAlarms()['raised'])->toBe(0);
    expect(FulfillmentAlarm::query()->count())->toBe(0);
})->with([
    'a manual service a booster delivers' => [fn (Order $order) => boosterItem($order)],
    'an item cancelled before it was placed' => [
        fn (Order $order) => coinsItem($order, ['status' => OrderItemStatus::Cancelled]),
    ],
    'an item refunded before it was placed' => [
        fn (Order $order) => coinsItem($order, ['status' => OrderItemStatus::Refunded]),
    ],
    'an item a supplier is already working on' => [
        function (Order $order): void {
            $item = coinsItem($order);
            FulfillmentJob::factory()->live()->create(['order_item_id' => $item->id]);
        },
    ],
]);

test('an unpaid order owes no supplier anything', function () {
    $order = orderPaidMinutesAgo(20, 'AUT-UNPAID-1');
    $order->forceFill(['paid_at' => null])->save();
    coinsItem($order);

    expect(sweepAlarms()['raised'])->toBe(0);
});

test('the alarm clears itself once the placement arrives', function () {
    $order = orderPaidMinutesAgo(20);
    $item = coinsItem($order);
    sweepAlarms();

    FulfillmentJob::factory()->live()->create(['order_item_id' => $item->id]);

    expect(sweepAlarms())->toMatchArray(['raised' => 0, 'resolved' => 1, 'open' => 0]);
    expect(FulfillmentAlarm::query()->sole()->resolved_at)->not->toBeNull();
});

test('a placed job whose supplier reads keep coming back empty raises a silent alarm', function () {
    $order = orderPaidMinutesAgo(20);
    $item = coinsItem($order);
    $job = FulfillmentJob::factory()->live()->create([
        'order_item_id' => $item->id,
        'poll_failure_count' => 6,
        'supplier_order_id' => 'FFT-4242',
    ]);

    expect(sweepAlarms())->toMatchArray(['raised' => 1, 'open' => 1]);

    $alarm = FulfillmentAlarm::query()->sole();
    expect($alarm->kind)->toBe(FulfillmentAlarmKind::Silent)
        ->and($alarm->context['poll_failures'] ?? null)->toBe(6)
        ->and($alarm->context['supplier_order_id'] ?? null)->toBe('FFT-4242');

    // One read landing resets the poller's counter, and the alarm goes with it.
    $job->forceFill(['poll_failure_count' => 0])->save();
    expect(sweepAlarms())->toMatchArray(['raised' => 0, 'resolved' => 1, 'open' => 0]);
});

test('a finished job is silent for the right reason and raises nothing', function () {
    $order = orderPaidMinutesAgo(20);
    $item = coinsItem($order);
    FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::Completed,
        'poll_failure_count' => 40,
    ]);

    expect(sweepAlarms()['raised'])->toBe(0);
});

test('a read that has not failed often enough is still just a retry', function () {
    $order = orderPaidMinutesAgo(20);
    $item = coinsItem($order);
    FulfillmentJob::factory()->live()->create([
        'order_item_id' => $item->id,
        'poll_failure_count' => 5,
    ]);

    expect(sweepAlarms()['raised'])->toBe(0);
});

test('with no owner address the alarm still stands, it is only unsent', function () {
    config()->set('store.order_alerts.email', null);
    $order = orderPaidMinutesAgo(20);
    coinsItem($order);
    sweepAlarms();

    expect(alertSilence())->toBe(0);
    Notification::assertNothingSent();
    expect(FulfillmentAlarm::query()->sole()->notified_at)->toBeNull();
});

test('one mail covers every alarm it accounts for, listed or counted', function () {
    for ($i = 1; $i <= 22; $i++) {
        $order = orderPaidMinutesAgo(20, "AUT-SILENT-{$i}");
        coinsItem($order);
    }

    expect(sweepAlarms()['raised'])->toBe(22);
    expect(alertSilence())->toBe(22);

    Notification::assertSentOnDemand(
        FulfillmentSilenceAlert::class,
        function (FulfillmentSilenceAlert $notification): bool {
            expect($notification->total)->toBe(22)
                ->and($notification->rows)->toHaveCount(20);

            return true;
        },
    );

    // Every alarm is stamped, including the two the mail only counted: an
    // unstamped row is a row the next sweep mails all over again.
    expect(FulfillmentAlarm::query()->whereNull('notified_at')->count())->toBe(0);
});

test('the admin panel counts what is open and how long it has been', function () {
    $order = orderPaidMinutesAgo(20);
    coinsItem($order);
    sweepAlarms();

    $health = app(ReadQueueHealth::class)->read();

    expect($health['silentItems'])->toBe(1)
        ->and($health['oldestSilenceAt'])->not->toBeNull();
});

// A PC challenge is the case this was written for: `forChallenge(Platform::Pc)`
// throws because the applied pricing run carries no PC cost tiers, so the
// request can never be composed and every retry fails the same way. Under one
// flat wait it looked exactly like an order still on its way to n8n, and the
// person who had to place it by hand heard at the same time either way.
test('a paid item the store has already refused to place does not wait the full quarter hour', function () {
    $order = orderPaidMinutesAgo(6, 'AUT-BLOCKED-1');
    coinsItem($order);
    undeliveredPlacement($order, 'budget_unavailable');

    expect(sweepAlarms())->toMatchArray(['raised' => 1, 'resolved' => 0, 'open' => 1]);

    $alarm = FulfillmentAlarm::query()->sole();
    expect($alarm->kind)->toBe(FulfillmentAlarmKind::Unplaced)
        ->and($alarm->context['reason'] ?? null)->toBe('budget_unavailable')
        ->and($alarm->context['blocked'] ?? null)->toBeTrue();
});

test('an order n8n has merely not acknowledged keeps the longer patience', function () {
    $order = orderPaidMinutesAgo(6, 'AUT-WAITING-1');
    coinsItem($order);
    undeliveredPlacement($order, 'delivery_failed');

    expect(sweepAlarms()['raised'])->toBe(0);

    // Past the ordinary wait it is an alarm like any other, and it says the
    // outbox is the thing that is stuck rather than the order.
    $this->travel(11)->minutes();

    expect(sweepAlarms())->toMatchArray(['raised' => 1, 'open' => 1]);
    expect(FulfillmentAlarm::query()->sole()->context['blocked'] ?? null)->toBeFalse();
});

test('a reason nobody has classified is treated as one a retry cannot clear', function () {
    $order = orderPaidMinutesAgo(6, 'AUT-UNKNOWN-1');
    coinsItem($order);
    undeliveredPlacement($order, 'some_reason_added_later');

    expect(sweepAlarms()['raised'])->toBe(1);
    expect(FulfillmentAlarm::query()->sole()->context['blocked'] ?? null)->toBeTrue();
});

test('a silence with no recorded reason claims none', function () {
    $order = orderPaidMinutesAgo(20);
    coinsItem($order);

    sweepAlarms();

    expect(FulfillmentAlarm::query()->sole()->context)->not->toHaveKey('reason');
});

test('a placed job nothing has read for an hour is its own kind of silence', function () {
    $order = orderPaidMinutesAgo(90, 'AUT-STALE-1');
    $item = coinsItem($order);
    $job = placedJob($item, observedMinutesAgo: 75);

    expect(sweepAlarms())->toMatchArray(['raised' => 1, 'resolved' => 0, 'open' => 1]);

    $alarm = FulfillmentAlarm::query()->sole();
    expect($alarm->kind)->toBe(FulfillmentAlarmKind::Stale)
        ->and($alarm->context['supplier_order_id'] ?? null)->toBe('FFT-9001')
        ->and($alarm->context['observed_at'] ?? null)->not->toBeNull();

    // One reading landing is the whole of the recovery.
    $job->forceFill(['observed_at' => now()])->save();

    expect(sweepAlarms())->toMatchArray(['raised' => 0, 'resolved' => 1, 'open' => 0]);
});

test('a job read within the hour is just a slow band', function () {
    $order = orderPaidMinutesAgo(90, 'AUT-STALE-2');
    placedJob(coinsItem($order), observedMinutesAgo: 20);

    expect(sweepAlarms()['raised'])->toBe(0);
});

// The failure counter only moves when something tries to read the job, so a
// placement recorded while the scheduler was down carries a null observation
// and nothing else. Age it from the placement instead, or the first sweep
// after a deploy alarms on a job that was recorded a second earlier.
test('a job never read is measured from the placement, not from nothing', function (int $placedMinutesAgo, int $expected) {
    $order = orderPaidMinutesAgo($placedMinutesAgo + 5, 'AUT-STALE-3');
    placedJob(coinsItem($order), observedMinutesAgo: null, attributes: [
        'created_at' => now()->subMinutes($placedMinutesAgo),
    ]);

    expect(sweepAlarms()['raised'])->toBe($expected);

    if ($expected === 1) {
        // Present and null, not absent: the operator reading the row has to be
        // able to tell "never read" from "went quiet".
        $context = FulfillmentAlarm::query()->sole()->context;
        expect($context)->toHaveKey('observed_at')
            ->and($context['observed_at'])->toBeNull();
    }
})->with([
    'recorded moments ago' => [2, 0],
    'recorded two hours ago and never read since' => [120, 1],
]);

test('a job whose reads are failing is silent, and only silent', function () {
    $order = orderPaidMinutesAgo(300, 'AUT-STALE-4');
    placedJob(coinsItem($order), observedMinutesAgo: 240, attributes: ['poll_failure_count' => 6]);

    expect(sweepAlarms())->toMatchArray(['raised' => 1, 'open' => 1]);
    expect(FulfillmentAlarm::query()->sole()->kind)->toBe(FulfillmentAlarmKind::Silent);
});

test('a job that went quiet long before the alarm shipped opens nothing new', function () {
    $order = orderPaidMinutesAgo(10 * 24 * 60, 'AUT-STALE-5');
    placedJob(coinsItem($order), observedMinutesAgo: 5 * 24 * 60);

    expect(sweepAlarms()['raised'])->toBe(0);
    expect(FulfillmentAlarm::query()->count())->toBe(0);
});

// A long order polled until this morning is today's outage even though it was
// paid for last week: the window is measured on the reading, not on the order.
test('an old order polled until an hour ago is still an alarm', function () {
    $order = orderPaidMinutesAgo(10 * 24 * 60, 'AUT-STALE-6');
    placedJob(coinsItem($order), observedMinutesAgo: 90);

    expect(sweepAlarms()['raised'])->toBe(1);
});

test('an order already closed is owed no reading', function (OrderStatus $status) {
    $order = orderPaidMinutesAgo(300, 'AUT-STALE-7');
    $order->forceFill(['status' => $status])->save();
    placedJob(coinsItem($order), observedMinutesAgo: 240);

    expect(sweepAlarms()['raised'])->toBe(0);
})->with([
    'completed' => [OrderStatus::Completed],
    'cancelled' => [OrderStatus::Cancelled],
    'refunded' => [OrderStatus::Refunded],
]);

test('a job with no supplier reference is an unplaced item, never a stale one', function () {
    $order = orderPaidMinutesAgo(300, 'AUT-STALE-8');
    placedJob(coinsItem($order), observedMinutesAgo: 240, attributes: [
        'supplier' => null,
        'supplier_order_id' => null,
    ]);

    expect(sweepAlarms()['raised'])->toBe(0);
});

test('the mail says which of the three silences each line is', function () {
    $blocked = orderPaidMinutesAgo(6, 'AUT-MAIL-1');
    coinsItem($blocked);
    undeliveredPlacement($blocked, 'credentials_purged');

    $stale = orderPaidMinutesAgo(300, 'AUT-MAIL-2');
    placedJob(coinsItem($stale), observedMinutesAgo: 240);

    $silent = orderPaidMinutesAgo(300, 'AUT-MAIL-3');
    placedJob(coinsItem($silent), observedMinutesAgo: 10, attributes: [
        'poll_failure_count' => 6,
        'supplier_order_id' => 'FFT-9002',
    ]);

    expect(sweepAlarms()['raised'])->toBe(3);
    expect(alertSilence())->toBe(3);

    Notification::assertSentOnDemand(
        FulfillmentSilenceAlert::class,
        function (FulfillmentSilenceAlert $notification): bool {
            $body = implode("\n", $notification->toMail(new stdClass)->introLines);

            expect($body)->toContain('AUT-MAIL-1: متوقف ولن يُرسل بدون تدخل')
                // The publisher's own word for it, not a translation of it: an
                // operator searching the log wants one spelling, not two.
                ->toContain('credentials_purged')
                ->toContain('AUT-MAIL-2: لا توجد قراءة جديدة من المورد')
                ->toContain('AUT-MAIL-3: المورد توقف عن الرد عليه');

            return true;
        },
    );
});
