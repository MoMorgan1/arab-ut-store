<?php

use App\Actions\Fulfillment\AlertOwnerOfFulfillmentSilence;
use App\Actions\Fulfillment\EnqueueOrderPlacement;
use App\Actions\Fulfillment\PublishOrderPaidEvent;
use App\Actions\Fulfillment\RecordSupplierPlacement;
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
use App\Support\Orders\PlacementBlockers;
use Illuminate\Support\Facades\Http;
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

/**
 * A job a supplier is working on.
 *
 * @param  array<string, mixed>  $attributes
 */
function placedJob(OrderItem $item, array $attributes = []): FulfillmentJob
{
    return FulfillmentJob::factory()->live()->create(array_replace([
        'order_item_id' => $item->id,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'FFT-9001',
        'poll_failure_count' => 0,
    ], $attributes));
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

test('the short wait begins exactly where the configuration says', function (int $paidMinutesAgo, int $expected) {
    $order = orderPaidMinutesAgo($paidMinutesAgo, 'AUT-EDGE-1');
    coinsItem($order);
    undeliveredPlacement($order, 'budget_unavailable');

    expect(sweepAlarms()['raised'])->toBe($expected);
})->with([
    'a minute short of the wait' => [4, 0],
    'exactly the wait' => [5, 1],
    'past it' => [6, 1],
]);

test('a reason the outbox records for its own sake is not the order being stuck', function (?string $reason, bool $blocks) {
    expect(PlacementBlockers::blocks($reason))->toBe($blocks);
})->with([
    // n8n did not answer. The next run may well land.
    'delivery_failed' => ['delivery_failed', false],
    // The row raced the order it names; nothing about the order is wrong.
    'order_missing' => ['order_missing', false],
    'no reason recorded yet' => [null, false],
    'an empty reason' => ['', false],
    'the store refusing to compose' => ['budget_unavailable', true],
    'a reason added after this was written' => ['something_new', true],
]);

// The reason on the alarm has to be the one the publisher actually wrote, not
// a string a test invented: the whole grading rests on that column, and on
// `markProcessed()` clearing it when a request finally lands.
test('the publisher writes the reason the alarm grades, and a delivery clears it', function () {
    config()->set('services.n8n.order_paid_url', 'https://n8n.example.test/webhook/paid');
    config()->set('services.n8n.order_paid_key', 'checkout-publisher');
    config()->set('services.n8n.order_paid_secret', str_repeat('s', 48));
    Http::preventStrayRequests();

    // No applied pricing run, so there is no budget to compose against and
    // `ReadSupplierCostTable` throws - the PC-challenge shape, reproduced with
    // the cheapest order that shows it.
    $order = orderPaidMinutesAgo(20, 'AUT-REAL-1');
    coinsItem($order, secretPayload: eaAccount());
    $event = app(EnqueueOrderPlacement::class)->execute($order);

    expect(app(PublishOrderPaidEvent::class)->execute($event))->toBeFalse()
        ->and($event->fresh()->last_error)->toBe('budget_unavailable');

    expect(sweepAlarms()['raised'])->toBe(1);
    expect(FulfillmentAlarm::query()->sole()->context['reason'] ?? null)->toBe('budget_unavailable');

    // Now the request can be composed and n8n takes it. The item is still
    // unplaced - n8n has not reported a reference back - so the alarm stands,
    // but it must stop claiming a reason that no longer exists.
    appliedPricingRun();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    // Past the backoff the release put on the row, or the publisher will not
    // even claim it.
    $this->travel(2)->minutes();

    expect(app(PublishOrderPaidEvent::class)->execute($event->fresh()))->toBeTrue()
        ->and($event->fresh()->last_error)->toBeNull();

    expect(sweepAlarms())->toMatchArray(['raised' => 0, 'resolved' => 0, 'open' => 1]);

    $context = FulfillmentAlarm::query()->sole()->context;
    expect($context)->not->toHaveKey('reason')
        ->and($context)->not->toHaveKey('blocked');
});

// A consequence of grading the wait, pinned because it looks like a bug and is
// not: an order whose blocking reason clears while it is still young goes back
// to the ordinary patience, so the alarm closes and re-opens at the quarter
// hour if the placement still has not landed. The first mail said "blocked",
// the second says "not placed yet", and both were true when they were sent.
test('an alarm raised on a blocking reason closes when the reason clears', function () {
    $order = orderPaidMinutesAgo(6, 'AUT-CLEARED-1');
    coinsItem($order);
    $event = undeliveredPlacement($order, 'budget_unavailable');

    expect(sweepAlarms())->toMatchArray(['raised' => 1, 'open' => 1]);

    $event->forceFill(['last_error' => null, 'status' => 'processed'])->save();

    expect(sweepAlarms())->toMatchArray(['raised' => 0, 'resolved' => 1, 'open' => 0]);

    // And back again on its own once the ordinary wait is up.
    $this->travel(10)->minutes();

    expect(sweepAlarms())->toMatchArray(['raised' => 1, 'resolved' => 0, 'open' => 1]);
    expect(FulfillmentAlarm::query()->sole()->context)->not->toHaveKey('reason');
});

// The alarm outlived the order it described. An admin completing or cancelling
// an order leaves the job row non-terminal, the poller stops reading it because
// its selection checks the order, and the failure counter freezes above the
// threshold - so the condition stayed true forever and the panel counted a
// finished order as silent until somebody edited the table.
test('a silent alarm closes when the order it describes is finished', function (callable $finish) {
    $order = orderPaidMinutesAgo(300, 'AUT-CLOSED-1');
    $item = coinsItem($order);
    $job = placedJob($item, ['poll_failure_count' => 6]);

    expect(sweepAlarms())->toMatchArray(['raised' => 1, 'open' => 1]);
    expect(alertSilence())->toBe(1);

    // The admin acts on the order; nothing touches the job row, and the
    // counter stays exactly where it was.
    $finish($order, $item);
    expect($job->fresh()->poll_failure_count)->toBe(6);

    expect(sweepAlarms())->toMatchArray(['raised' => 0, 'resolved' => 1, 'open' => 0]);
    expect(FulfillmentAlarm::query()->sole()->resolved_at)->not->toBeNull();
})->with([
    'the order was completed' => [
        fn (Order $order) => $order->forceFill(['status' => OrderStatus::Completed])->save(),
    ],
    'the order was cancelled' => [
        fn (Order $order) => $order->forceFill(['status' => OrderStatus::Cancelled])->save(),
    ],
    'the order was refunded' => [
        fn (Order $order) => $order->forceFill(['status' => OrderStatus::Refunded])->save(),
    ],
    'only that item was refunded' => [
        fn (Order $order, OrderItem $item) => $item->forceFill(['status' => OrderItemStatus::Refunded])->save(),
    ],
]);

// End to end rather than one state at a time: raised, mailed once, and closed
// by the thing that actually ends the silence - a read landing, which is the
// poller resetting the counter.
test('a silence is mailed once, then closed by the read that ends it', function () {
    $order = orderPaidMinutesAgo(300, 'AUT-CYCLE-1');
    $job = placedJob(coinsItem($order), ['poll_failure_count' => 6]);

    expect(sweepAlarms())->toMatchArray(['raised' => 1, 'resolved' => 0, 'open' => 1]);
    expect(alertSilence())->toBe(1);
    Notification::assertSentOnDemand(FulfillmentSilenceAlert::class);

    // A second sweep while it is still silent changes nothing and mails nothing.
    Notification::fake();
    expect(sweepAlarms())->toMatchArray(['raised' => 0, 'resolved' => 0, 'open' => 1]);
    expect(alertSilence())->toBe(0);
    Notification::assertNothingSent();

    $job->forceFill(['poll_failure_count' => 0, 'observed_at' => now()])->save();

    expect(sweepAlarms())->toMatchArray(['raised' => 0, 'resolved' => 1, 'open' => 0]);
    expect(alertSilence())->toBe(0);
    Notification::assertNothingSent();

    // And it is one row reused, not a new one per episode: the table is state,
    // not a log.
    expect(FulfillmentAlarm::query()->count())->toBe(1);
});

// `RecordSupplierPlacement` is the only writer of a job row, and it writes the
// supplier and the reference together on both of its paths - so "a job with no
// reference" is not a shape the store can produce. The silence pass is not
// filtered on the reference for that reason: it watches a counter that only
// rises when a read was attempted, so it catches such a row anyway if one ever
// appears, while the item itself is out of the unplaced pass the moment a job
// exists at all.
test('a recorded placement always carries the reference the poller needs', function () {
    $order = paidOrder('AUT-SHAPE-1');
    $item = coinsItem($order);

    $result = app(RecordSupplierPlacement::class)->execute([
        'order_item_public_id' => (string) $item->public_id,
        'supplier' => 'fft',
        'supplier_order_id' => 'FFT-7788',
        'delivery_phase' => 'coins',
    ]);

    expect($result['outcome'])->toBe('recorded');

    $job = FulfillmentJob::query()->where('order_item_id', $item->id)->sole();
    expect($job->supplier)->toBe(Supplier::Fft)
        ->and($job->supplier_order_id)->toBe('FFT-7788');
});

test('a hand-made job with no reference is still caught by its failure count', function () {
    $order = orderPaidMinutesAgo(300, 'AUT-SHAPE-2');
    $item = coinsItem($order);
    placedJob($item, [
        'supplier' => null,
        'supplier_order_id' => null,
        'poll_failure_count' => 6,
    ]);

    expect(sweepAlarms())->toMatchArray(['raised' => 1, 'open' => 1]);
    expect(FulfillmentAlarm::query()->sole()->kind)->toBe(FulfillmentAlarmKind::Silent);
});

test('the mail says which silence each line is', function () {
    $blocked = orderPaidMinutesAgo(6, 'AUT-MAIL-1');
    coinsItem($blocked);
    undeliveredPlacement($blocked, 'credentials_purged');

    $waiting = orderPaidMinutesAgo(20, 'AUT-MAIL-2');
    coinsItem($waiting);

    $silent = orderPaidMinutesAgo(300, 'AUT-MAIL-3');
    placedJob(coinsItem($silent), ['poll_failure_count' => 6, 'supplier_order_id' => 'FFT-9002']);

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
                ->toContain('AUT-MAIL-2: لم يُرسل لأي مورد بعد')
                ->toContain('AUT-MAIL-3: المورد توقف عن الرد عليه');

            return true;
        },
    );
});
