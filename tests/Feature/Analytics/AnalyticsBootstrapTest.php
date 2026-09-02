<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;

function analyticsOrder(User $user, OrderStatus $status, int $paymentHalalah, int $walletHalalah = 0): Order
{
    $order = Order::factory()->for($user)->create([
        'status' => $status,
        'subtotal_halalah' => $paymentHalalah + $walletHalalah,
        'payment_halalah' => $paymentHalalah,
        'wallet_halalah' => $walletHalalah,
        'total_halalah' => $paymentHalalah + $walletHalalah,
    ]);

    OrderItem::factory()->for($order)->create([
        'sku' => 'COINS-PS-100K',
        'name_en' => 'Coins 100K',
        'status' => OrderItemStatus::InProgress,
        'quantity' => 2,
        'unit_price_halalah' => 5_000,
    ]);

    return $order;
}

test('the consent bootstrap is absent until a vendor id is configured', function () {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('__arabutAnalytics', false)
        ->assertDontSee("gtag('consent', 'default'", false);
});

test('the storefront renders the consent default before anything else and no vendor script', function () {
    config()->set('services.analytics.ga4_measurement_id', 'G-TEST123');
    config()->set('services.analytics.meta_pixel_id', '');

    $html = $this->get('/cart')->assertOk()->getContent();

    expect($html)
        ->toContain("gtag('consent', 'default'")
        ->toContain('window.__arabutAnalytics = {"ga4":"G-TEST123"}')
        ->not->toContain('googletagmanager.com')
        ->not->toContain('connect.facebook.net')
        ->not->toContain('analytics.tiktok.com')
        ->and(strpos($html, "gtag('consent', 'default'"))->toBeLessThan(strpos($html, '__arabutAnalytics'));
});

test('the account order page carries the bootstrap while auth and admin pages do not', function () {
    config()->set('services.analytics.meta_pixel_id', '987654321');

    // Guest first: actingAs() sticks to the test case, and a signed-in
    // visit to /login redirects away.
    $this->get('/login')->assertOk()->assertDontSee('__arabutAnalytics', false);

    $user = User::factory()->create();
    $order = analyticsOrder($user, OrderStatus::InProgress, 10_000);

    $this->actingAs($user)
        ->get('/my-account/orders/'.$order->public_id)
        ->assertOk()
        ->assertSee('window.__arabutAnalytics = {"meta":"987654321"}', false);
    $this->get('/my-account/orders')->assertOk()->assertDontSee('__arabutAnalytics', false);
});

test('a Paylink-paid order exposes a purchase payload for the Paylink amount only', function () {
    $user = User::factory()->create();
    $order = analyticsOrder($user, OrderStatus::InProgress, 7_500, 2_500);

    $this->actingAs($user)
        ->get('/my-account/orders/'.$order->public_id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.analytics.orderId', $order->public_id)
            ->where('order.analytics.value', 75)
            ->where('order.analytics.currency', 'SAR')
            ->where('order.analytics.items.0.id', 'COINS-PS-100K')
            ->where('order.analytics.items.0.name', 'Coins 100K')
            ->where('order.analytics.items.0.quantity', 2)
            ->where('order.analytics.items.0.price', 50));
});

test('wallet-only, pending and cancelled orders expose no purchase payload', function (OrderStatus $status, int $payment, int $wallet) {
    $user = User::factory()->create();
    $order = analyticsOrder($user, $status, $payment, $wallet);

    $this->actingAs($user)
        ->get('/my-account/orders/'.$order->public_id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('order.analytics', null));
})->with([
    'wallet only' => [OrderStatus::InProgress, 0, 10_000],
    'pending payment' => [OrderStatus::PendingPayment, 10_000, 0],
    'cancelled' => [OrderStatus::Cancelled, 10_000, 0],
]);

test('the analytics env keys mirror the services config one to one', function () {
    $environment = (string) file_get_contents(base_path('.env.example'));

    expect($environment)
        ->toContain('ANALYTICS_GA4_MEASUREMENT_ID=')
        ->toContain('ANALYTICS_META_PIXEL_ID=')
        ->toContain('ANALYTICS_TIKTOK_PIXEL_ID=')
        ->and(config('services.analytics'))->toHaveKeys(['ga4_measurement_id', 'meta_pixel_id', 'tiktok_pixel_id']);
});

test('the privacy page names the vendors and links the tracking opt-out in both locales', function (string $uri, string $off, string $on) {
    $this->get($uri)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('page.blocks', fn ($blocks) => collect($blocks)
                ->flatMap(fn (array $block) => $block['type'] === 'paragraph' ? $block['content'] : [])
                ->pluck('url')
                ->filter()
                ->values()
                ->all() === [$off, $on]));
})->with([
    'Arabic' => ['/privacy', '/privacy?tracking=off', '/privacy?tracking=on'],
    'English' => ['/en/privacy', '/en/privacy?tracking=off', '/en/privacy?tracking=on'],
]);
