<?php

use App\Actions\Fulfillment\AlertOwnerOfFulfillmentSilence;
use App\Actions\Fulfillment\SweepFulfillmentAlarms;
use App\Admin\Queries\ReadQueueHealth;
use App\Enums\FulfillmentAlarmKind;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\FulfillmentAlarm;
use App\Models\FulfillmentJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Notifications\FulfillmentSilenceAlert;
use Illuminate\Support\Facades\Notification;

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
