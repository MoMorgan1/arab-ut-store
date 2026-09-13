<?php

use App\Actions\Orders\IssueOrderTrackingLink;
use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTrackingLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function trackLinkOrder(OrderStatus $status = OrderStatus::InProgress): Order
{
    return Order::factory()->create([
        'order_number' => 'UT-'.fake()->unique()->numerify('########'),
        'status' => $status,
        'placed_at' => now(),
    ]);
}

function trackLinkItem(Order $order, ServiceType $serviceType = ServiceType::Coins): OrderItem
{
    return OrderItem::factory()->for($order)->create([
        'name_ar' => 'خدمة كوينز',
        'name_en' => 'Coins Service',
        'service_type' => $serviceType,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::InProgress,
    ]);
}

/**
 * A placed job, so the item carries something to track. Overrides model an
 * unplaced job deliberately.
 *
 * @param  array<string, mixed>  $attributes
 */
function trackLinkJob(OrderItem $item, array $attributes = []): FulfillmentJob
{
    return FulfillmentJob::factory()->create([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => 'fft-'.$item->id,
        ...$attributes,
    ]);
}

/** A placed SBC item whose single challenge asks for a sign-in correction. */
function trackLinkChallengeItem(Order $order): OrderItem
{
    $item = trackLinkItem($order, ServiceType::Sbc);
    $challengeId = '1803b7a6-0000-0000-0000-00000064265f';

    $job = trackLinkJob($item, [
        'delivery_phase' => DeliveryPhase::Challenge,
        'observation' => [$challengeId => ['sbcStatus' => 'WrongUserPass']],
    ]);

    FulfillmentPlacement::factory()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Challenge,
        'supplier' => Supplier::Fft,
        'supplier_order_id' => $job->supplier_order_id,
        'supplier_challenge_ids' => [$challengeId],
        'idempotency_key' => 'placement-'.fake()->unique()->word(),
        'placed_at' => now(),
    ]);

    return $item;
}

function trackLinkPath(Order $order): string
{
    $url = app(IssueOrderTrackingLink::class)->execute($order);

    return (string) parse_url($url, PHP_URL_PATH);
}

function trackLinkToken(Order $order): string
{
    return (string) basename((string) parse_url(
        app(IssueOrderTrackingLink::class)->execute($order),
        PHP_URL_PATH,
    ));
}

test('a valid token renders the page with number, status and item tracking', function (): void {
    $order = trackLinkOrder();
    $item = trackLinkItem($order);
    trackLinkJob($item, ['coins_delivered' => 50_000, 'coins_ordered' => 100_000]);

    $this->get(trackLinkPath($order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/track-order')
            ->where('order.number', $order->order_number)
            ->where('order.status', 'in_progress')
            ->where('order.items.0.tracking.kind', 'coins')
            ->where('order.items.0.tracking.progress.coinsDelivered', 50_000)
        );
});

test('the payload carries none of the excluded keys', function (): void {
    $order = trackLinkOrder();
    $item = trackLinkItem($order);
    trackLinkJob($item);

    $this->get(trackLinkPath($order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('order.total')
            ->missing('order.subtotal')
            ->missing('order.discount')
            ->missing('order.paymentAmount')
            ->missing('order.walletPayment')
            ->missing('order.paymentMethod')
            ->missing('order.analytics')
            ->missing('order.review')
            ->missing('order.cancelUrl')
            ->missing('order.paymentStartUrl')
            ->missing('order.id')
            ->missing('order.public_id')
            ->missing('order.items.0.id')
            ->missing('order.items.0.public_id')
            ->missing('order.items.0.actionUrls')
            ->missing('order.items.0.credentialsPresent')
            ->missing('order.items.0.manualFulfillment')
        );
});

test('every item and challenge action list is blanked', function (): void {
    $order = trackLinkOrder();

    $coins = trackLinkItem($order, ServiceType::Coins);
    trackLinkJob($coins, ['allowed_actions' => ['resume']]);

    trackLinkChallengeItem($order);

    $response = $this->get(trackLinkPath($order))->assertOk();

    $items = $response->inertiaPage()['props']['order']['items'];

    foreach ($items as $item) {
        if ($item['tracking'] === null) {
            continue;
        }

        expect($item['tracking']['actions'])->toBe([]);

        foreach ($item['tracking']['challenges'] ?? [] as $challenge) {
            expect($challenge['actions'])->toBe([]);
        }
    }
});

test('an unknown token 404s', function (): void {
    $this->get('/orders/track/'.Str::random(48))->assertNotFound();
});

test('a revoked token 404s indistinguishably from an unknown token', function (): void {
    $order = trackLinkOrder();
    $action = app(IssueOrderTrackingLink::class);
    $firstToken = trackLinkToken($order);

    $action->revoke($order);

    $revoked = $this->get('/orders/track/'.$firstToken);
    $unknown = $this->get('/orders/track/'.Str::random(48));

    $revoked->assertNotFound();
    $unknown->assertNotFound();

    expect($revoked->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($revoked->getContent())->not->toContain($order->order_number)
        ->and($revoked->getContent())->not->toContain($firstToken);
});

test('a malformed token never reaches the resolver', function (string $token): void {
    $this->get('/orders/track/'.$token)->assertNotFound();
})->with([
    'too short' => [str_repeat('A', 47)],
    'too long' => [str_repeat('A', 49)],
    'with a symbol' => [str_repeat('A', 47).'!'],
]);

test('issuing a link is idempotent and leaves one row', function (): void {
    $order = trackLinkOrder();
    $action = app(IssueOrderTrackingLink::class);

    $first = $action->execute($order);
    $second = $action->execute($order);

    expect($second)->toBe($first)
        ->and(OrderTrackingLink::query()->where('order_id', $order->id)->count())->toBe(1);
});

test('revoking then re-issuing produces a fresh token and the old one 404s', function (): void {
    $order = trackLinkOrder();
    $action = app(IssueOrderTrackingLink::class);
    $firstToken = trackLinkToken($order);

    $action->revoke($order);

    $secondToken = trackLinkToken($order);

    expect($secondToken)->not->toBe($firstToken);

    $this->get('/orders/track/'.$firstToken)->assertNotFound();
    $this->get('/orders/track/'.$secondToken)->assertOk();
});

test('the token is stored only as a hash and an encrypted blob', function (): void {
    $order = trackLinkOrder();
    $token = trackLinkToken($order);

    $link = OrderTrackingLink::query()->where('order_id', $order->id)->first();

    expect($link->token_hash)->not->toBe($token)
        ->and($link->getRawOriginal('token_encrypted'))->not->toContain($token);

    foreach ((array) DB::table('order_tracking_links')->where('order_id', $order->id)->first() as $value) {
        expect((string) $value)->not->toContain($token);
    }
});

test('a successful read stamps last_used_at', function (): void {
    $order = trackLinkOrder();
    trackLinkItem($order);

    $path = trackLinkPath($order);

    $link = OrderTrackingLink::query()->where('order_id', $order->id)->first();

    expect($link->last_used_at)->toBeNull();

    $this->get($path)->assertOk();

    $link->refresh();

    expect($link->last_used_at)->not->toBeNull();
});

test('the track route is throttled by a registered named limiter', function (): void {
    expect(RateLimiter::limiter('order-tracking-link'))->not->toBeNull();

    $route = Route::getRoutes()->getByName('store.orders.track');

    expect($route)->not->toBeNull()
        ->and($route->middleware())->toContain('throttle:order-tracking-link');
});

test('a terminal order still opens the link and reports refreshable false', function (): void {
    $order = trackLinkOrder(OrderStatus::Completed);
    $item = trackLinkItem($order);
    trackLinkJob($item);

    $this->get(trackLinkPath($order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/track-order')
            ->where('order.status', 'completed')
            ->where('order.refreshable', false)
            ->where('order.items.0.tracking.actions', [])
        );
});
