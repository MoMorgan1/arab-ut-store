<?php

use App\Actions\Fulfillment\ComposePlacementRequest;
use App\Actions\Fulfillment\EnqueueOrderPlacement;
use App\Actions\Fulfillment\PublishOrderPaidEvent;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\CatalogSource;
use App\Models\FulfillmentJob;
use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SecretAccessLog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function enqueue(Order $order): IntegrationEvent
{
    $event = app(EnqueueOrderPlacement::class)->execute($order);

    expect($event)->toBeInstanceOf(IntegrationEvent::class);

    return $event;
}

function n8nAcknowledges(): void
{
    Http::fake(['https://n8n.example.test/*' => Http::response(['data' => ['acknowledged' => true]])]);
}

/** @return array<string, mixed> */
function sentBody(int $index = 0): array
{
    $bodies = [];
    Http::assertSent(function (Request $request) use (&$bodies): bool {
        $bodies[] = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

        return true;
    });

    return $bodies[$index];
}

beforeEach(function (): void {
    config()->set('services.n8n.order_paid_url', 'https://n8n.example.test/webhook/arab-ut-order-paid');
    config()->set('services.n8n.order_paid_key', 'checkout-publisher');
    config()->set('services.n8n.order_paid_secret', str_repeat('s', 48));
    Http::preventStrayRequests();
});

test('the placement request carries the item, its budget and its EA account, and the outbox row carries none of it', function () {
    appliedPricingRun();
    $order = paidOrder();
    $item = coinsItem($order, secretPayload: eaAccount());
    $event = enqueue($order);
    n8nAcknowledges();

    expect(app(PublishOrderPaidEvent::class)->execute($event))->toBeTrue();

    $event->refresh();
    expect($event->schema_version)->toBe(2)
        ->and($event->status)->toBe('processed')
        ->and($event->attempts)->toBe(1)
        ->and($event->last_error)->toBeNull();

    $stored = json_encode($event->payload, JSON_THROW_ON_ERROR);
    expect($stored)->not->toContain('fahad@example.test')
        ->not->toContain('safe password')
        ->not->toContain('11111111')
        ->not->toContain('budget');

    Http::assertSent(function (Request $request) use ($event): bool {
        $raw = $request->body();
        $timestamp = $request->header('X-ArabUT-Timestamp')[0] ?? '';
        $expected = hash_hmac('sha256', $timestamp."\n".$event->event_id."\n".$raw, str_repeat('s', 48));

        return $request->url() === 'https://n8n.example.test/webhook/arab-ut-order-paid'
            && $request->method() === 'POST'
            && $request->hasHeader('X-ArabUT-Key', 'checkout-publisher')
            && $request->hasHeader('X-ArabUT-Event', $event->event_id)
            && $request->hasHeader('X-ArabUT-Signature', $expected);
    });

    $body = sentBody();
    expect($body['schemaVersion'])->toBe(2)
        ->and($body['data']['order_number'])->toBe('AUT-PAID-1001')
        ->and($body['data']['channel'])->toBe('store')
        ->and($body['data']['customer_name'])->toBe('Fahad Al-Otaibi')
        ->and($body['data'])->not->toHaveKeys(['customer_phone', 'customer_email'])
        ->and($body['data']['items'])->toHaveCount(1)
        ->and($body['data']['items'][0])->toMatchArray([
            'order_item_public_id' => (string) $item->public_id,
            'service' => 'coins',
            'platform' => 'playstation',
            'supplier_platform' => 'PS',
            'quantity' => 1,
            'coins' => ['quantity' => 1_250_000, 'delivery' => 'fast'],
            // 1,250K falls in the 2M tier: 11.5 USD/M ÷ 1.15 USD/EUR ÷ 10 = 1.00 EUR per 100K.
            'budget' => ['max_eur_per_100k' => 1.0, 'basis' => 'console_fast:2000K', 'pricing_version' => 12],
            'account' => [
                'ea_email' => 'fahad@example.test',
                'ea_password' => 'safe password',
                'backup_codes' => ['11111111', '22222222', '33333333'],
                'current_balance' => 350_000,
                'credential_version' => 1,
            ],
        ]);

    // The decryption is on the record, attributed to the placement and not to a person.
    $access = SecretAccessLog::query()->sole();
    expect($access->purpose)->toBe(ComposePlacementRequest::ACCESS_PURPOSE)
        ->and($access->user_id)->toBeNull()
        ->and($access->order_item_secret_id)->toBe($item->secret->id);

    expect(app(PublishOrderPaidEvent::class)->execute($event->fresh()))->toBeTrue();
    Http::assertSentCount(1);
});

test('a retried send re-reads the account, so a correction made meanwhile is what the supplier receives', function () {
    appliedPricingRun();
    $order = paidOrder();
    $item = coinsItem($order, secretPayload: eaAccount());
    $event = enqueue($order);

    Http::fake(['https://n8n.example.test/*' => Http::sequence()
        ->push(['data' => ['acknowledged' => false]], 503)
        ->push(['data' => ['acknowledged' => true]])]);
    expect(app(PublishOrderPaidEvent::class)->execute($event))->toBeFalse();

    $event->refresh();
    expect($event->status)->toBe('pending')
        ->and($event->last_error)->toBe('delivery_failed');

    // The customer corrects their password before the next attempt.
    $secret = $item->secret;
    $secret->encrypted_payload = eaAccount(['ea_password' => 'corrected password']);
    $secret->forceFill(['version' => 2])->save();

    IntegrationEvent::query()->whereKey($event->id)->update(['available_at' => now()->subMinute()]);

    expect(app(PublishOrderPaidEvent::class)->execute($event->fresh()))->toBeTrue();

    $body = sentBody(1);
    expect($body['data']['items'][0]['account']['ea_password'])->toBe('corrected password')
        ->and($body['data']['items'][0]['account']['credential_version'])->toBe(2)
        ->and(SecretAccessLog::query()->count())->toBe(2);
});

test('slow console delivery is budgeted at the cycle cost, PC by its own tiers, and a challenge at the second tier', function () {
    appliedPricingRun();
    $order = paidOrder();
    coinsItem($order, [
        'configuration' => ['service_type' => 'coins', 'platform' => 'playstation', 'market' => 'console', 'delivery' => 'normal', 'coins_quantity' => 400_000],
    ], eaAccount());
    coinsItem($order, [
        'platform' => Platform::Pc,
        'configuration' => ['service_type' => 'coins', 'platform' => 'pc', 'market' => 'pc', 'delivery' => null, 'coins_quantity' => 7_000_000],
    ], eaAccount(['ea_email' => 'pc@example.test']));

    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'source_id' => CatalogSource::factory(),
        'external_id' => 'easysbc-sbc-412',
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['service_type' => ServiceType::Sbc, 'platform' => Platform::PlayStation]);
    $challenge = OrderItem::factory()->for($order)->create([
        'product_variant_id' => $variant->id,
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::Received,
        'quantity' => 2,
        'configuration' => ['service_type' => 'sbc', 'platform' => 'playstation', 'market' => 'console', 'completion_count' => 3],
    ]);
    $secret = new OrderItemSecret(['order_item_id' => $challenge->id, 'masked_summary' => []]);
    $secret->encrypted_payload = eaAccount(['ea_email' => 'sbc@example.test']);
    $secret->save();

    $event = enqueue($order);
    n8nAcknowledges();

    expect(app(PublishOrderPaidEvent::class)->execute($event))->toBeTrue();

    $items = collect(sentBody()['data']['items'])->keyBy('service');
    $coins = collect(sentBody()['data']['items'])->where('service', 'coins')->values();

    // 9.2 ÷ 1.15 ÷ 10 = 0.80
    expect($coins[0]['budget'])->toMatchArray(['max_eur_per_100k' => 0.8, 'basis' => 'cycle:ps'])
        // 7M sits in the 10M tier: 27 ÷ 1.15 ÷ 10 = 2.35
        ->and($coins[1]['supplier_platform'])->toBe('PC')
        ->and($coins[1]['budget'])->toMatchArray(['max_eur_per_100k' => 2.35, 'basis' => 'pc:10000K'])
        ->and($items['sbc']['sbc'])->toBe(['set_id' => 412, 'times_to_solve' => 6])
        ->and($items['sbc']['budget'])->toMatchArray(['max_eur_per_100k' => 1.0, 'basis' => 'console_fast:2000K']);
});

test('an item already placed is left out, and an order whose every item is placed is finished without a request', function () {
    appliedPricingRun();
    $order = paidOrder();
    $placed = coinsItem($order, secretPayload: eaAccount());
    $open = coinsItem($order, secretPayload: eaAccount(['ea_email' => 'open@example.test']));
    $event = enqueue($order);

    FulfillmentJob::factory()->live()->create(['order_item_id' => $placed->id]);
    n8nAcknowledges();

    expect(app(PublishOrderPaidEvent::class)->execute($event))->toBeTrue();

    $items = sentBody()['data']['items'];
    expect($items)->toHaveCount(1)
        ->and($items[0]['order_item_public_id'])->toBe((string) $open->public_id);

    // A second order: staff paste the last reference between payment and the send.
    $other = Order::factory()->create(['status' => OrderStatus::Received, 'paid_at' => now()]);
    $item = coinsItem($other, secretPayload: eaAccount());
    $second = enqueue($other);
    FulfillmentJob::factory()->live()->create(['order_item_id' => $item->id]);

    expect(app(PublishOrderPaidEvent::class)->execute($second))->toBeTrue();
    Http::assertSentCount(1);
    expect($second->fresh()->status)->toBe('processed')
        ->and(SecretAccessLog::query()->count())->toBe(1);
});

test('nothing is queued for an order with nothing to place', function () {
    $order = paidOrder();
    OrderItem::factory()->for($order)->create([
        'service_type' => ServiceType::Rivals,
        'status' => OrderItemStatus::Received,
        'configuration' => ['service_type' => 'rivals', 'platform' => 'playstation'],
    ]);

    expect(app(EnqueueOrderPlacement::class)->execute($order))->toBeNull()
        ->and(IntegrationEvent::query()->count())->toBe(0);

    $placed = coinsItem($order, secretPayload: eaAccount());
    FulfillmentJob::factory()->live()->create(['order_item_id' => $placed->id]);

    expect(app(EnqueueOrderPlacement::class)->execute($order))->toBeNull();

    coinsItem($order, secretPayload: eaAccount());

    expect(app(EnqueueOrderPlacement::class)->execute($order))->toBeInstanceOf(IntegrationEvent::class);
});

test('a request that cannot be composed is released with the reason, and no secret is read for nothing', function (callable $arrange, string $reason) {
    $order = paidOrder();
    $arrange($order);
    $event = enqueue($order);
    Http::fake();

    expect(app(PublishOrderPaidEvent::class)->execute($event))->toBeFalse();

    $event->refresh();
    expect($event->status)->toBe('pending')
        ->and($event->attempts)->toBe(1)
        ->and($event->available_at->isAfter(now()))->toBeTrue()
        ->and($event->last_error)->toBe($reason);
    Http::assertNothingSent();
})->with([
    'no applied pricing run' => [
        function (Order $order): void {
            coinsItem($order, secretPayload: eaAccount());
        },
        'budget_unavailable',
    ],
    'a pricing run without the cost table' => [
        function (Order $order): void {
            appliedPricingRun(['tierCosts' => null]);
            coinsItem($order, secretPayload: eaAccount());
        },
        'budget_unavailable',
    ],
    'no EA account on the item' => [
        function (Order $order): void {
            appliedPricingRun();
            coinsItem($order);
        },
        'credentials_missing',
    ],
    'an email without a password' => [
        function (Order $order): void {
            appliedPricingRun();
            coinsItem($order, secretPayload: ['ea_email' => 'staff@example.test', 'backup_codes' => []]);
        },
        'credentials_incomplete',
    ],
    'a purged account' => [
        function (Order $order): void {
            appliedPricingRun();
            $item = coinsItem($order, secretPayload: eaAccount());
            $item->secret->forceFill(['deleted_at' => now()])->save();
        },
        'credentials_purged',
    ],
    'a challenge whose product names no supplier id' => [
        function (Order $order): void {
            appliedPricingRun();
            $item = OrderItem::factory()->for($order)->create([
                'service_type' => ServiceType::Sbc,
                'platform' => Platform::PlayStation,
                'status' => OrderItemStatus::Received,
                'configuration' => ['service_type' => 'sbc', 'platform' => 'playstation', 'completion_count' => 1],
            ]);
            $secret = new OrderItemSecret(['order_item_id' => $item->id, 'masked_summary' => []]);
            $secret->encrypted_payload = eaAccount();
            $secret->save();
        },
        'challenge_unknown',
    ],
    'a platform no supplier serves' => [
        function (Order $order): void {
            appliedPricingRun();
            coinsItem($order, ['platform' => Platform::Xbox], eaAccount());
        },
        'platform_unsupported',
    ],
]);

test('publisher failures remain retryable and never persist provider response details', function () {
    appliedPricingRun();
    $order = paidOrder();
    coinsItem($order, secretPayload: eaAccount());
    $event = enqueue($order);
    Http::fake(['https://n8n.example.test/*' => Http::response(['secret' => 'never-store-this'], 503)]);

    expect(app(PublishOrderPaidEvent::class)->execute($event))->toBeFalse();

    $event->refresh();
    expect($event->status)->toBe('pending')
        ->and($event->attempts)->toBe(1)
        ->and($event->available_at->isAfter(now()))->toBeTrue()
        ->and($event->last_error)->toBe('delivery_failed')
        ->and(json_encode($event->toArray(), JSON_THROW_ON_ERROR))->not->toContain('never-store-this');
});

test('missing or unsafe publisher configuration fails closed before the network', function (string $field, mixed $value) {
    config()->set("services.n8n.{$field}", $value);
    appliedPricingRun();
    $order = paidOrder();
    coinsItem($order, secretPayload: eaAccount());
    $event = enqueue($order);
    Http::fake();

    expect(app(PublishOrderPaidEvent::class)->execute($event))->toBeFalse();
    Http::assertNothingSent();
})->with([
    'missing URL' => ['order_paid_url', null],
    'insecure URL' => ['order_paid_url', 'http://n8n.example.test/webhook/order'],
    'missing key' => ['order_paid_key', null],
    'short secret' => ['order_paid_secret', 'short'],
]);
