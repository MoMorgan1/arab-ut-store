<?php

use App\Actions\Cart\ResolveCartOwner;
use App\Actions\Catalog\StoreCatalogReader;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\StoreSuggestions;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{product: Product, variant: ProductVariant} */
function suggestionSbcProduct(int $index): array
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'slug' => "sbc-suggestion-{$index}",
        'name_ar' => "تحدي {$index}",
        'name_en' => "SBC Challenge {$index}",
        'description_ar' => "وصف تحدي {$index}",
        'description_en' => "Description for challenge {$index}",
        'is_visible' => true,
        'archived_at' => null,
        'sort_order' => $index,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'price_halalah' => 10_000 * $index,
        'sale_price_halalah' => null,
        'price_version' => 4,
        'is_active' => true,
    ]);

    return compact('product', 'variant');
}

function suggestionCart(): Cart
{
    return Cart::query()->create([
        'user_id' => null,
        'session_key' => hash('sha256', 'suggestion-cart-'.uniqid('', true)),
        'status' => 'active',
        'currency' => 'SAR',
    ]);
}

/** @param array<string, mixed> $configuration */
function suggestionCartItem(Cart $cart, ProductVariant $variant, string $serviceType, array $configuration = []): void
{
    $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => 12_500,
        'total_halalah' => 12_500,
        'configuration' => array_replace([
            'service_type' => $serviceType,
            'platform' => 'playstation',
            'market' => 'console',
            'quoted_at' => now()->utc()->toIso8601String(),
            'price_version' => 4,
        ], $configuration),
    ]);
}

/** @return array{products: list<array<string, mixed>>, services: list<array<string, mixed>>, reason: string|null, sbcUrl: string} */
function suggestFor(Cart $cart, string $locale = 'ar'): array
{
    app()->setLocale($locale);

    return app(StoreSuggestions::class)->forCart(
        $cart->refresh(),
        Request::create($locale === 'en' ? '/en/cart' : '/cart', 'GET'),
        $locale,
        'SAR',
    );
}

test('cart suggestions exclude products already in the cart and cap at two', function (): void {
    ['variant' => $ownedVariant] = suggestionSbcProduct(1);
    suggestionSbcProduct(2);
    suggestionSbcProduct(3);
    suggestionSbcProduct(4);

    $cart = suggestionCart();
    suggestionCartItem($cart, $ownedVariant, 'sbc', ['completion_count' => 1]);

    $result = suggestFor($cart);

    expect(array_column($result['products'], 'name'))->toBe(['تحدي 2', 'تحدي 3'])
        ->and($result['services'])->toHaveCount(2)
        ->and(array_column($result['services'], 'key'))->toBe(['rivals', 'fut_champions'])
        ->and($result['reason'])->toBe('مع التحدي')
        ->and($result['sbcUrl'])->toBe('/sbc');
});

test('a coins-only cart suggests two challenges plus both manual services', function (): void {
    suggestionSbcProduct(1);
    suggestionSbcProduct(2);

    $coinsProduct = Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'slug' => 'fc-coins',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $coinsVariant = ProductVariant::factory()->for($coinsProduct)->create([
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'price_halalah' => 12_500,
        'is_active' => true,
    ]);

    $cart = suggestionCart();
    suggestionCartItem($cart, $coinsVariant, 'coins', ['coins_quantity' => 100_000, 'delivery' => 'fast']);

    $result = suggestFor($cart);

    expect($result['products'])->toHaveCount(2)
        ->and($result['services'])->toHaveCount(2)
        ->and($result['reason'])->toBe('مع الكوينز');
});

test('manual services already in the cart are not suggested again', function (): void {
    suggestionSbcProduct(1);

    $rivalsProduct = Product::factory()->create([
        'service_type' => ServiceType::Rivals,
        'slug' => 'suggestion-rivals-service',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $rivalsVariant = ProductVariant::factory()->for($rivalsProduct)->create([
        'service_type' => ServiceType::Rivals,
        'platform' => Platform::PlayStation,
        'price_halalah' => 15_000,
        'is_active' => true,
    ]);

    $cart = suggestionCart();
    suggestionCartItem($cart, $rivalsVariant, 'rivals');

    $result = suggestFor($cart);

    expect(array_column($result['services'], 'key'))->toBe(['fut_champions'])
        ->and($result['reason'])->toBe('مع الرايفلز');
});

test('the reason tag follows the first cart line', function (string $serviceType, string $expected): void {
    suggestionSbcProduct(1);

    $product = Product::factory()->create([
        'service_type' => ServiceType::from($serviceType),
        'slug' => "service-{$serviceType}",
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::from($serviceType),
        'platform' => Platform::PlayStation,
        'price_halalah' => 12_500,
        'is_active' => true,
    ]);

    $cart = suggestionCart();
    suggestionCartItem($cart, $variant, $serviceType);

    expect(suggestFor($cart)['reason'])->toBe($expected);
})->with([
    'coins' => ['coins', 'مع الكوينز'],
    'rivals' => ['rivals', 'مع الرايفلز'],
    'fut_champions' => ['fut_champions', 'مع الفوت'],
    'sbc' => ['sbc', 'مع التحدي'],
]);

test('the reason tag is localized', function (): void {
    suggestionSbcProduct(1);

    $product = Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'slug' => 'fc-coins',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'price_halalah' => 12_500,
        'is_active' => true,
    ]);

    $cart = suggestionCart();
    suggestionCartItem($cart, $variant, 'coins', ['coins_quantity' => 100_000, 'delivery' => 'fast']);

    $result = suggestFor($cart, 'en');

    expect($result['reason'])->toBe('With coins')
        ->and($result['products'][0]['name'])->toBe('SBC Challenge 1');
});

test('an empty cart returns empty suggestions', function (): void {
    app()->setLocale('ar');

    $empty = app(StoreSuggestions::class)->forCart(
        null,
        Request::create('/cart', 'GET'),
        'ar',
        'SAR',
    );

    expect($empty)->toBe(['products' => [], 'services' => [], 'reason' => null, 'sbcUrl' => '/sbc']);

    $cartWithoutItems = suggestionCart();

    expect(suggestFor($cartWithoutItems))->toBe(['products' => [], 'services' => [], 'reason' => null, 'sbcUrl' => '/sbc']);
});

test('a catalog outage leaves services up while products go empty', function (): void {
    suggestionSbcProduct(1);

    $product = Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'slug' => 'fc-coins',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'price_halalah' => 12_500,
        'is_active' => true,
    ]);

    $cart = suggestionCart();
    suggestionCartItem($cart, $variant, 'coins', ['coins_quantity' => 100_000, 'delivery' => 'fast']);

    // The catalog reader is final and cannot be mocked, so the outage is
    // simulated through the suggestions' own catalog hook instead.
    $outage = new class(app(StoreCatalogReader::class)) extends StoreSuggestions
    {
        protected function readSbcProducts(string $locale, string $displayCurrency): array
        {
            throw new DomainException('catalog outage');
        }
    };
    app()->setLocale('ar');

    $result = $outage->forCart(
        $cart->refresh(),
        Request::create('/cart', 'GET'),
        'ar',
        'SAR',
    );

    expect($result['products'])->toBe([])
        ->and($result['services'])->toHaveCount(2)
        ->and($result['reason'])->toBe('مع الكوينز');
});

test('the cart page exposes suggestions outside the partially reloaded cart', function (): void {
    ['variant' => $ownedVariant] = suggestionSbcProduct(1);
    suggestionSbcProduct(2);

    $cart = suggestionCart();
    suggestionCartItem($cart, $ownedVariant, 'sbc', ['completion_count' => 1]);
    $rawToken = str_repeat('cd', 32);

    $sessionKey = hash_hmac('sha256', $rawToken, (string) config('app.key'));
    $cart->forceFill(['session_key' => $sessionKey])->save();

    $this->withSession([ResolveCartOwner::SESSION_KEY => $rawToken])
        ->get('/cart')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/cart', false)
            ->has('cartPage.suggestions.products', 1)
            ->where('cartPage.suggestions.products.0.name', 'تحدي 2')
            ->has('cartPage.suggestions.services', 2)
            ->where('cartPage.suggestions.reason', 'مع التحدي')
            ->where('cartPage.suggestions.sbcUrl', '/sbc')
            ->has('cart.items', 1));
});
