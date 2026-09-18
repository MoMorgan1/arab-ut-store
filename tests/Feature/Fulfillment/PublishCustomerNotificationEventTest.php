<?php

use App\Actions\Fulfillment\PublishCustomerNotificationEvent;
use App\Enums\NotificationStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Fulfillment\Notifications\QueueCustomerNotification;
use App\Models\IntegrationEvent;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\OrderItem;
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

    $notification = app(QueueCustomerNotification::class)->forItem($order, $item, $template, 4242, 'ar');
    $event = IntegrationEvent::query()->whereKey($notification->integration_event_id)->firstOrFail();

    return [$order, $item, $notification, $event];
}

beforeEach(function (): void {
    config()->set('services.n8n.customer_notify_url', 'https://n8n.example.test/webhook/arab-ut-customer-notify');
    config()->set('services.n8n.customer_notify_key', 'notify-publisher');
    config()->set('services.n8n.customer_notify_secret', str_repeat('n', 48));
    Http::preventStrayRequests();
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
