<?php

use App\Actions\Fulfillment\PublishCustomerNotificationEvent;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\NotificationStatus;
use App\Enums\OrderHoldReason;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Fulfillment\Notifications\CustomerNotificationCatalog;
use App\Fulfillment\Notifications\QueueCustomerNotification;
use App\Models\FulfillmentJob;
use App\Models\IntegrationEvent;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{0: Order, 1: OrderItem, 2: NotificationDelivery, 3: IntegrationEvent} */
function notifyReadyDelivery(string $template = 'credentials'): array
{
    $customer = User::factory()->create([
        'first_name' => 'Fahad',
        'last_name' => 'Al-Otaibi',
        'phone' => '+966512345678',
    ]);

    $order = Order::factory()->for($customer)->create([
        'order_number' => 'AUT-SEND-'.strtoupper((string) Str::random(6)),
        'status' => OrderStatus::WaitingForCustomer,
        'locale' => 'ar',
        'channel' => 'store',
        'currency' => 'SAR',
        'paid_at' => now(),
        'subtotal_halalah' => 20_000,
        'payment_halalah' => 20_000,
        'total_halalah' => 20_000,
    ]);

    $item = OrderItem::factory()->for($order)->create([
        'status' => OrderItemStatus::WaitingForCustomer,
        'unit_price_halalah' => 20_000,
        'subtotal_halalah' => 20_000,
        'total_halalah' => 20_000,
    ]);

    $reason = OrderHoldReason::from($template);

    // The card the message points at: the job carries the hold and offers
    // exactly the buttons this wording names, which is what the publisher
    // re-checks before it sends.
    FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'delivery_phase' => DeliveryPhase::Coins,
        'hold_reason' => $reason,
        'allowed_actions' => array_map(
            fn (SupplierAction $action): string => $action->value,
            CustomerNotificationCatalog::buttonsFor($reason) ?? [],
        ),
    ]);

    $history = OrderStatusHistory::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'actor_user_id' => null,
        'status' => OrderStatusHistoryStatus::WaitingForCustomer,
        'note_ar' => null,
        'note_en' => null,
        'metadata' => ['source' => 'supplier'],
    ]);

    $notification = app(QueueCustomerNotification::class)
        ->forItem($order, $item, $template, (int) $history->id, 'ar', $reason, 'supplier');
    $event = IntegrationEvent::query()->whereKey($notification->integration_event_id)->firstOrFail();

    return [$order, $item, $notification, $event];
}

beforeEach(function (): void {
    config()->set('services.n8n.customer_notify_url', 'https://n8n.example.test/webhook/arab-ut-customer-notify');
    config()->set('services.n8n.customer_notify_key', 'notify-publisher');
    config()->set('services.n8n.customer_notify_secret', str_repeat('n', 48));
    Http::preventStrayRequests();
});

/** Repoints the queued row's card at a different set of buttons. */
function repointCard(OrderItem $item, SupplierAction ...$actions): void
{
    FulfillmentJob::query()
        ->where('order_item_id', $item->id)
        ->update(['allowed_actions' => array_map(
            static fn (SupplierAction $action): string => $action->value,
            $actions,
        )]);
}

test('a queued challenge wording still sends on the card that earned it', function (): void {
    [, $item, $notification] = notifyReadyDelivery('market_locked');
    $notification->forceFill(['template_key' => 'market_locked_challenge'])->save();
    repointCard($item, SupplierAction::RetryChallenge);

    Http::fake(['*' => Http::response(['data' => ['acknowledged' => true]], 200)]);
    Artisan::call('orders:publish-customer-notifications');

    expect($notification->fresh()->status)->toBe(NotificationStatus::Sent);
});

test('a queued default wording is not sent on a card that only offers retry', function (): void {
    [, $item, $notification] = notifyReadyDelivery('market_locked');

    // The item is still held for the same reason and the card still offers a
    // button - just not the one THIS row's sentence names. Asking the reason
    // rather than the stored template would answer "some wording fits" and
    // send «تشغيل الطلب» to a customer looking at «إعادة المحاولة».
    repointCard($item, SupplierAction::RetryChallenge);

    Http::fake(['*' => Http::response(['data' => ['acknowledged' => true]], 200)]);
    Artisan::call('orders:publish-customer-notifications');

    expect($notification->fresh()->status)->not->toBe(NotificationStatus::Sent);
    Http::assertNothingSent();
});

test('the publisher is inert until the webhook is configured', function (): void {
    config()->set('services.n8n.customer_notify_url', null);
    [$order, $item, $notification, $event] = notifyReadyDelivery();

    expect(app(PublishCustomerNotificationEvent::class)->execute($event->fresh()))->toBeFalse();

    $exit = Artisan::call('orders:publish-customer-notifications');

    expect($exit)->toBe(0);
    Http::assertNothingSent();

    expect($event->fresh()->status)->toBe('pending')
        ->and($event->fresh()->attempts)->toBe(0)
        ->and($event->fresh()->last_error)->toBeNull()
        ->and($notification->fresh()->status)->toBe(NotificationStatus::Queued);
});

test('the wire body carries the recipient, the message and the idempotency key', function (): void {
    [$order, $item, $notification, $event] = notifyReadyDelivery();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    app(PublishCustomerNotificationEvent::class)->execute($event);

    expect($notification->fresh()->status)->toBe(NotificationStatus::Sent)
        ->and($notification->fresh()->sent_at)->not->toBeNull()
        ->and($event->fresh()->status)->toBe('processed')
        ->and($event->fresh()->attempts)->toBe(1);

    Http::assertSent(function (Request $request) use ($event): bool {
        $raw = $request->body();
        $timestamp = $request->header('X-ArabUT-Timestamp')[0] ?? '';
        $expected = hash_hmac('sha256', $timestamp."\n".$event->event_id."\n".$raw, str_repeat('n', 48));

        return $request->url() === 'https://n8n.example.test/webhook/arab-ut-customer-notify'
            && $request->method() === 'POST'
            && $request->hasHeader('X-ArabUT-Key', 'notify-publisher')
            && $request->hasHeader('X-ArabUT-Event', $event->event_id)
            && $request->hasHeader('X-ArabUT-Signature', $expected);
    });

    Http::assertSent(function (Request $request) use ($order, $notification): bool {
        $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

        return $body['eventType'] === 'customer.notify'
            && $body['schemaVersion'] === 1
            && $body['data']['template'] === 'credentials'
            && $body['data']['locale'] === 'ar'
            && $body['data']['order_number'] === $order->order_number
            && $body['data']['idempotency_key'] === $notification->idempotency_key
            && $body['data']['to'] === '966512345678'
            && str_contains((string) $body['data']['body'], $order->order_number)
            && str_contains((string) $body['data']['body'], '/orders/track/')
            && ! str_contains((string) $body['data']['body'], 'track.arab-ut.com');
    });
});

test('a refusal or an outage defers with the reason, on a backoff', function (): void {
    [$order, $item, $notification, $event] = notifyReadyDelivery();
    Http::fake(['https://n8n.example.test/*' => Http::response('boom', 500)]);

    expect(app(PublishCustomerNotificationEvent::class)->execute($event))->toBeFalse();

    $event->refresh();
    expect($event->status)->toBe('pending')
        ->and($event->attempts)->toBe(1)
        ->and($event->last_error)->toBe('delivery_failed')
        ->and($event->available_at->isFuture())->toBeTrue();

    expect($notification->fresh()->status)->toBe(NotificationStatus::Queued)
        ->and($notification->fresh()->last_error)->toBe('delivery_failed');
});

test('an unacknowledged answer is a deferral, not a send', function (): void {
    [$order, $item, $notification, $event] = notifyReadyDelivery();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => false]])]);

    expect(app(PublishCustomerNotificationEvent::class)->execute($event))->toBeFalse()
        ->and($event->fresh()->status)->toBe('pending')
        ->and($notification->fresh()->status)->toBe(NotificationStatus::Queued);
});

test('a hold that cleared while the message waited expires instead of sending', function (): void {
    [$order, $item, $notification, $event] = notifyReadyDelivery();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    $item->forceFill(['status' => OrderItemStatus::InProgress])->save();

    expect(app(PublishCustomerNotificationEvent::class)->execute($event))->toBeTrue();

    Http::assertNothingSent();

    expect($notification->fresh()->status)->toBe(NotificationStatus::Expired)
        ->and($event->fresh()->status)->toBe('processed');
});

test('a completed order expires its hold message', function (): void {
    [$order, $item, $notification, $event] = notifyReadyDelivery();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    $order->forceFill(['status' => OrderStatus::Completed])->save();

    app(PublishCustomerNotificationEvent::class)->execute($event);

    Http::assertNothingSent();
    expect($notification->fresh()->status)->toBe(NotificationStatus::Expired);
});

test('a missing phone defers with the reason and sends nothing', function (): void {
    [$order, $item, $notification, $event] = notifyReadyDelivery();
    $order->user->forceFill(['phone' => null])->save();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    expect(app(PublishCustomerNotificationEvent::class)->execute($event))->toBeFalse();

    Http::assertNothingSent();
    expect($event->fresh()->last_error)->toBe('recipient_missing')
        ->and($notification->fresh()->last_error)->toBe('recipient_missing');
});

test('a held send lock refuses without burning an attempt', function (): void {
    [$order, $item, $notification, $event] = notifyReadyDelivery();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    $lock = Cache::lock('customer-notify:item:'.((string) $item->public_id), 60);
    expect($lock->acquire())->toBeTrue();

    try {
        expect(app(PublishCustomerNotificationEvent::class)->execute($event))->toBeFalse();
    } finally {
        $lock->release();
    }

    Http::assertNothingSent();
    expect($event->fresh()->status)->toBe('pending')
        ->and($event->fresh()->attempts)->toBe(0);
});

test('no stored row ever holds the full number', function (): void {
    [$order, $item, $notification, $event] = notifyReadyDelivery();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    app(PublishCustomerNotificationEvent::class)->execute($event);

    foreach (NotificationDelivery::query()->get() as $row) {
        $stored = json_encode([$row->payload, $row->recipient_masked, $row->last_error], JSON_THROW_ON_ERROR);
        expect($stored)->not->toContain('966512345678');
    }

    foreach (IntegrationEvent::query()->get() as $row) {
        $stored = json_encode([$row->payload, $row->last_error], JSON_THROW_ON_ERROR);
        expect($stored)->not->toContain('966512345678');
    }
});

test('a later hold supersedes the message the earlier one queued', function (): void {
    [$order, $item, $notification, $event] = notifyReadyDelivery();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    // The customer fixed the credentials, the order ran, and it stopped again
    // on something else. Two history rows later, the first message describes a
    // problem that is over - and the second one, queued for the new hold, is
    // the one that should go.
    OrderStatusHistory::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'actor_user_id' => null,
        'status' => OrderStatusHistoryStatus::InProgress,
        'metadata' => ['source' => 'supplier'],
    ]);

    $second = OrderStatusHistory::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'actor_user_id' => null,
        'status' => OrderStatusHistoryStatus::WaitingForCustomer,
        'metadata' => ['source' => 'admin'],
    ]);

    FulfillmentJob::query()->where('order_item_id', $item->id)->update([
        'hold_reason' => OrderHoldReason::Credentials->value,
        'allowed_actions' => json_encode([SupplierAction::EditCredentials->value]),
    ]);

    $newer = app(QueueCustomerNotification::class)->forItem(
        $order->fresh(),
        $item->fresh(),
        'backup_codes',
        (int) $second->id,
        'ar',
        OrderHoldReason::BackupCodes,
        'admin',
    );

    expect(app(PublishCustomerNotificationEvent::class)->execute($event))->toBeTrue();
    Http::assertNothingSent();
    expect($notification->fresh()->status)->toBe(NotificationStatus::Expired)
        ->and($notification->fresh()->last_error)->toBe('hold_no_longer_current');

    // And the admin's message, whose reason lives in its transition rather
    // than on the job, is not judged against the job's stale supplier reason.
    $newerEvent = IntegrationEvent::query()->whereKey($newer->integration_event_id)->firstOrFail();

    expect(app(PublishCustomerNotificationEvent::class)->execute($newerEvent))->toBeTrue()
        ->and($newer->fresh()->status)->toBe(NotificationStatus::Sent);

    Http::assertSentCount(1);
});

test('a hold that changed reason without changing status expires', function (): void {
    [$order, $item, $notification, $event] = notifyReadyDelivery();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    // A supplier read can move the reason while the item stays waiting, and
    // then it writes no history row at all. The queued message would still be
    // about credentials.
    FulfillmentJob::query()->where('order_item_id', $item->id)->update([
        'hold_reason' => OrderHoldReason::Captcha->value,
        'allowed_actions' => json_encode([SupplierAction::Resume->value]),
    ]);

    expect(app(PublishCustomerNotificationEvent::class)->execute($event))->toBeTrue();

    Http::assertNothingSent();
    expect($notification->fresh()->status)->toBe(NotificationStatus::Expired);
});

test('a card that lost the button the message names expires it', function (): void {
    [$order, $item, $notification, $event] = notifyReadyDelivery();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    // Same hold, same status, but the edit form is no longer offered. The
    // message says «تعديل بيانات الطلب»; sending it now would point at
    // nothing.
    FulfillmentJob::query()->where('order_item_id', $item->id)->update([
        'allowed_actions' => json_encode([SupplierAction::RetryChallenge->value]),
    ]);

    expect(app(PublishCustomerNotificationEvent::class)->execute($event))->toBeTrue();

    Http::assertNothingSent();
    expect($notification->fresh()->status)->toBe(NotificationStatus::Expired);
});

test('retiring an exhausted event fails its delivery row with it', function (): void {
    [$order, $item, $notification, $event] = notifyReadyDelivery();
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);

    $event->forceFill(['attempts' => 10, 'last_error' => 'delivery_failed', 'available_at' => now()->subMinute()])->save();

    Artisan::call('orders:publish-customer-notifications');

    expect($event->fresh()->status)->toBe('failed');

    // A notification still reading `queued` beside a failed event says it is
    // owed when nothing will send it - and the requeue command, which looks
    // for a failed row, would not find it.
    $notification->refresh();
    expect($notification->status)->toBe(NotificationStatus::Failed)
        ->and($notification->failed_at)->not->toBeNull();

    Artisan::call('orders:requeue-paid-event', ['event_id' => $event->event_id]);

    expect($event->fresh()->status)->toBe('pending')
        ->and($notification->fresh()->status)->toBe(NotificationStatus::Queued)
        ->and($notification->fresh()->failed_at)->toBeNull();
});
