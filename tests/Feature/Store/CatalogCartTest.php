<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

function createCatalogCartVariant(array $product = [], array $variant = []): ProductVariant
{
    $service = $product['service_type'] ?? ServiceType::Sbc;
    $model = Product::factory()->create([
        'service_type' => $service,
        'slug' => $product['slug'] ?? fake()->unique()->slug(),
        'name_ar' => $product['name_ar'] ?? 'خدمة اختبار',
        'name_en' => $product['name_en'] ?? 'Test service',
        'is_visible' => $product['is_visible'] ?? true,
        'archived_at' => $product['archived_at'] ?? null,
    ]);

    return ProductVariant::factory()->for($model)->create([
        'service_type' => $service,
        'platform' => $variant['platform'] ?? Platform::PlayStation,
        'price_halalah' => $variant['price_halalah'] ?? 12_500,
        'sale_price_halalah' => $variant['sale_price_halalah'] ?? null,
        'price_version' => $variant['price_version'] ?? 1,
        'is_active' => $variant['is_active'] ?? true,
    ]);
}

function postCatalogVariant(string $path, ProductVariant $variant, string $key): TestResponse
{
    return test()->postJson($path, ['variantId' => $variant->public_id], ['Idempotency-Key' => $key]);
}

test('a guest adds one eligible authoritative catalog variant to the cart', function () {
    $variant = createCatalogCartVariant();

    $response = postCatalogVariant('/cart/items/catalog', $variant, (string) Str::ulid());
    $response
        ->assertCreated()
        ->assertJsonPath('data.cartCount', 1)
        ->assertJsonPath('data.cartUrl', '/cart');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $item = CartItem::sole();
    expect($item->unit_price_halalah)->toBe(12_500)
        ->and($item->total_halalah)->toBe(12_500)
        ->and($item->configuration)->toMatchArray([
            'service_type' => 'sbc',
            'platform' => 'playstation',
            'market' => 'console',
            'price_version' => 1,
        ])
        ->and($item->secret)->toBeNull();
});

test('catalog addition uses sale price and supports authenticated and isolated guest owners', function () {
    $variant = createCatalogCartVariant([], ['price_halalah' => 20_000, 'sale_price_halalah' => 15_000]);

    postCatalogVariant('/cart/items/catalog', $variant, (string) Str::ulid())->assertCreated();
    $guestCart = Cart::sole();

    $this->actingAs(User::factory()->create());
    postCatalogVariant('/en/cart/items/catalog', $variant, (string) Str::ulid())
        ->assertCreated()
        ->assertJsonPath('data.cartUrl', '/en/cart');

    expect(Cart::count())->toBe(2)
        ->and($guestCart->fresh()?->items()->count())->toBe(1)
        ->and(CartItem::query()->pluck('unit_price_halalah')->unique()->all())->toBe([15_000]);
});

test('same request replays one line while a mismatched idempotency replay conflicts', function () {
    $first = createCatalogCartVariant();
    $second = createCatalogCartVariant(['slug' => 'second']);
    $key = (string) Str::ulid();

    postCatalogVariant('/cart/items/catalog', $first, $key)->assertCreated();
    postCatalogVariant('/cart/items/catalog', $first, $key)->assertCreated();
    postCatalogVariant('/cart/items/catalog', $second, $key)->assertConflict();

    expect(CartItem::count())->toBe(1);
});

test('catalog cart rejects untrusted fields and ineligible variants', function (array $product, array $variant) {
    $model = createCatalogCartVariant($product, $variant);

    postCatalogVariant('/cart/items/catalog', $model, (string) Str::ulid())
        ->assertUnprocessable();
    expect(CartItem::count())->toBe(0);
})->with([
    'hidden product' => [['is_visible' => false], []],
    'archived product' => [['archived_at' => '2026-08-11 00:00:00'], []],
    'inactive variant' => [[], ['is_active' => false]],
    'Coins variant' => [['service_type' => ServiceType::Coins], []],
    'zero price' => [[], ['price_halalah' => 0]],
]);

test('catalog cart rejects a variant whose service disagrees with its product', function () {
    $variant = createCatalogCartVariant(['service_type' => ServiceType::Sbc]);
    $variant->update(['service_type' => ServiceType::Objectives]);

    postCatalogVariant('/cart/items/catalog', $variant, (string) Str::ulid())
        ->assertUnprocessable();
    expect(CartItem::count())->toBe(0);
});

test('catalog cart accepts JSON with exactly one public variant id', function () {
    $variant = createCatalogCartVariant();
    $key = (string) Str::ulid();

    $this->postJson('/cart/items/catalog', [
        'variantId' => $variant->public_id,
        'price' => 1,
        'configuration' => ['service_type' => 'sbc'],
    ], ['Idempotency-Key' => $key])->assertUnprocessable();

    $response = $this->post('/cart/items/catalog', ['variantId' => $variant->public_id], [
        'Idempotency-Key' => $key,
        'Accept' => 'text/html',
    ])->assertStatus(415);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('cart projection contains only safe localized product data for catalog lines', function () {
    $variant = createCatalogCartVariant([
        'name_ar' => 'خدمة أيكون آمنة',
        'name_en' => 'Safe Icon Service',
    ], ['platform' => Platform::Xbox]);
    postCatalogVariant('/cart/items/catalog', $variant, (string) Str::ulid())->assertCreated();

    $this->get('/cart')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('cart.items.0.product.name', 'خدمة أيكون آمنة')
            ->where('cart.items.0.product.serviceType', 'sbc')
            ->where('cart.items.0.product.imageUrl', null)
            ->where('cart.items.0.configuration.platform', 'xbox')
            ->where('cart.items.0.requiresCredentials', true)
            ->missing('cart.items.0.product.source')
            ->missing('cart.items.0.product.externalId'));

    $this->get('/en/cart')
        ->assertInertia(fn (Assert $page) => $page
            ->where('cart.items.0.product.name', 'Safe Icon Service'));
});
