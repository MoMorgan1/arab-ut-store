<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $product
 * @param  array<string, mixed>  $variant
 */
function createStoreCatalogProduct(ServiceType $service, array $product = [], array $variant = []): Product
{
    $model = Product::factory()->create([
        'service_type' => $service,
        'slug' => $product['slug'] ?? fake()->unique()->slug(),
        'name_ar' => $product['name_ar'] ?? 'خدمة عربية',
        'name_en' => $product['name_en'] ?? 'English service',
        'description_ar' => $product['description_ar'] ?? 'وصف عربي',
        'description_en' => $product['description_en'] ?? 'English description',
        'is_visible' => $product['is_visible'] ?? true,
        'archived_at' => $product['archived_at'] ?? null,
        'sort_order' => $product['sort_order'] ?? 0,
        'created_at' => $product['created_at'] ?? now(),
    ]);

    ProductVariant::factory()->for($model)->create([
        'service_type' => $service,
        'platform' => $variant['platform'] ?? Platform::PlayStation,
        'price_halalah' => $variant['price_halalah'] ?? 10_000,
        'sale_price_halalah' => $variant['sale_price_halalah'] ?? null,
        'configuration' => $variant['configuration'] ?? [],
        'is_active' => $variant['is_active'] ?? true,
    ]);

    return $model;
}

/** @param list<array{completions:int,multiplierBps:int,totalMinor:int}> $tiers */
function storeSbcCompletionPricing(array $tiers, ?int $maximum = null): array
{
    return [
        'sbcCategory' => 'players',
        'completionPricing' => [
            'version' => 1,
            'repeatable' => true,
            'maximum' => $maximum,
            'tiers' => $tiers,
        ],
    ];
}

test('the bilingual service routes expose the correct catalog page contracts', function (
    string $path,
    string $component,
    string $service,
    string $locale,
) {
    createStoreCatalogProduct(ServiceType::from($service), ['slug' => "{$service}-service"]);

    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component, false)
            ->where('locale', $locale)
            ->where('catalog.service', $service)
            ->has($component === 'store/category' ? 'servicePage.title' : 'productPage.add_to_cart')
            ->missing('catalog.rawPayload')
            ->missing('catalog.source'));
})->with([
    'Arabic SBC category' => ['/sbc', 'store/category', 'sbc', 'ar'],
    'English Objectives category' => ['/en/objectives', 'store/category', 'objectives', 'en'],
    'Arabic FUT Champions product' => ['/fut-champions', 'store/catalog-product', 'fut_champions', 'ar'],
    'English Rivals product' => ['/en/rivals', 'store/catalog-product', 'rivals', 'en'],
]);

test('category listings expose only public products and active variants', function () {
    createStoreCatalogProduct(ServiceType::Sbc, [
        'slug' => 'icon-service',
        'name_ar' => 'تحدي أيكون',
        'name_en' => 'Icon challenge',
    ], [
        'platform' => Platform::Xbox,
        'price_halalah' => 12_000,
        'sale_price_halalah' => 9_000,
        'configuration' => ['sbcCategory' => 'icons'],
    ]);
    createStoreCatalogProduct(ServiceType::Sbc, ['slug' => 'hidden'], ['is_active' => false]);
    createStoreCatalogProduct(ServiceType::Sbc, ['slug' => 'private', 'is_visible' => false]);
    createStoreCatalogProduct(ServiceType::Sbc, ['slug' => 'archived', 'archived_at' => now()]);
    createStoreCatalogProduct(ServiceType::Objectives, ['slug' => 'wrong-service']);

    $this->get('/sbc?filter=icons')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/category', false)
            ->has('catalog.products', 1)
            ->where('catalog.products.0.slug', 'icon-service')
            ->where('catalog.products.0.url', '/sbc/icon-service')
            ->where('catalog.products.0.name', 'تحدي أيكون')
            ->where('catalog.products.0.price.amountMinor', 9_000)
            ->where('catalog.products.0.price.currency', 'SAR')
            ->where('catalog.products.0.platforms', ['xbox'])
            ->missing('catalog.products.0.externalId')
            ->missing('catalog.products.0.configuration'));
});

test('category filters accept the empty search value sent by the storefront form', function () {
    createStoreCatalogProduct(ServiceType::Sbc, [
        'slug' => 'empty-search-icon',
    ], [
        'configuration' => ['sbcCategory' => 'icons'],
    ]);

    $this->get('/sbc?filter=icons&q=&sort=recommended')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('catalog.products', 1)
            ->where('catalog.products.0.slug', 'empty-search-icon')
            ->where('catalog.query.filter', 'icons')
            ->where('catalog.query.q', ''));
});

test('SBC listings expose truthful filter counts and every product remains reachable through pagination', function () {
    foreach (range(1, 13) as $index) {
        createStoreCatalogProduct(ServiceType::Sbc, [
            'slug' => "player-challenge-{$index}",
            'sort_order' => $index,
        ], [
            'configuration' => ['sbcCategory' => 'players'],
        ]);
    }

    createStoreCatalogProduct(ServiceType::Sbc, [
        'slug' => 'icon-challenge',
        'sort_order' => 14,
    ], [
        'configuration' => ['sbcCategory' => 'icons'],
    ]);

    $this->get('/en/sbc?page=2&q=&sort=recommended')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('catalog.products', 2)
            ->where('catalog.products.0.slug', 'player-challenge-13')
            ->where('catalog.products.1.slug', 'icon-challenge')
            ->where('catalog.query.page', 2)
            ->where('catalog.pagination', [
                'page' => 2,
                'perPage' => 12,
                'total' => 14,
                'lastPage' => 2,
            ])
            ->where('catalog.filterCounts', [
                'all' => 14,
                'players' => 13,
                'icons' => 1,
                'upgrades' => 0,
                'foundations' => 0,
            ]));
});

test('newest catalog sorting keeps created time ahead of the id tie breaker', function () {
    createStoreCatalogProduct(ServiceType::Sbc, [
        'slug' => 'newer-lower-id',
        'created_at' => '2026-08-12 12:00:00',
    ]);
    createStoreCatalogProduct(ServiceType::Sbc, [
        'slug' => 'older-higher-id',
        'created_at' => '2026-08-11 12:00:00',
    ]);

    $this->get('/en/sbc?sort=newest')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.products.0.slug', 'newer-lower-id')
            ->where('catalog.products.1.slug', 'older-higher-id'));
});

test('catalog pagination retrieves only the requested page within a fixed query budget', function () {
    foreach (range(1, 60) as $index) {
        createStoreCatalogProduct(ServiceType::Sbc, [
            'slug' => "budget-challenge-{$index}",
            'sort_order' => $index,
        ], [
            'configuration' => ['sbcCategory' => $index % 2 === 0 ? 'players' : 'icons'],
        ]);
    }

    $retrievedProducts = 0;
    Product::retrieved(function () use (&$retrievedProducts): void {
        $retrievedProducts++;
    });
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get('/en/sbc?page=3&q=service&filter=all&sort=recommended')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('catalog.products', 12)
            ->where('catalog.pagination.total', 60));

    expect($retrievedProducts)->toBeLessThanOrEqual(12);
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(12);
});

test('SBC upgrades includes source challenges and supports localized search and stable price sorting', function () {
    createStoreCatalogProduct(ServiceType::Sbc, [
        'slug' => 'upgrade-expensive',
        'name_ar' => 'تطوير مميز',
        'name_en' => 'Premium upgrade',
    ], ['price_halalah' => 20_000, 'configuration' => ['sbcCategory' => 'upgrades']]);
    createStoreCatalogProduct(ServiceType::Sbc, [
        'slug' => 'challenge-cheap',
        'name_ar' => 'تطوير سريع',
        'name_en' => 'Quick upgrade',
    ], ['price_halalah' => 10_000, 'configuration' => ['sbcCategory' => 'challenges']]);

    $this->get('/sbc?filter=upgrades&q=تطوير&sort=price_asc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('catalog.products', 2)
            ->where('catalog.products.0.slug', 'challenge-cheap')
            ->where('catalog.products.1.slug', 'upgrade-expensive')
            ->where('catalog.query.filter', 'upgrades')
            ->where('catalog.query.sort', 'price_asc')
            ->where('catalog.query.q', 'تطوير'));
});

test('catalog prices use one fresh selected display currency and fail open as unavailable when the rate is missing', function () {
    CarbonImmutable::setTestNow('2026-08-11 12:00:00 UTC');
    createStoreCatalogProduct(ServiceType::Sbc, ['slug' => 'converted'], ['price_halalah' => 10_000]);
    ExchangeRate::create([
        'base_currency' => 'SAR',
        'quote_currency' => 'EUR',
        'rate' => '0.25000000',
        'source' => 'test',
        'fetched_at' => now(),
    ]);

    $this->withSession(['display_currency' => 'EUR'])
        ->get('/en/sbc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.products.0.price', ['amountMinor' => 2_500, 'currency' => 'EUR']));

    ExchangeRate::query()->delete();

    $this->withSession(['display_currency' => 'EUR'])
        ->get('/en/sbc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.products.0.price', null));
});

test('category query input is bounded and product routes reject wrong services', function () {
    createStoreCatalogProduct(ServiceType::Objectives, ['slug' => 'objective-one']);

    $this->get('/objectives?page=0&q='.str_repeat('x', 81))->assertSessionHasErrors(['page', 'q']);
    $this->get('/sbc/objective-one')->assertNotFound();
    $this->get('/objectives/missing')->assertNotFound();
});

test('category product pages resolve the slug in both storefront locales', function () {
    $current = createStoreCatalogProduct(ServiceType::Sbc, [
        'slug' => 'localized-sbc-product',
        'name_ar' => 'ØªØ­Ø¯ÙŠ Ù…Ø­Ù„ÙŠ',
        'name_en' => 'Localized challenge',
    ], [
        'configuration' => ['sbcCategory' => 'players'],
    ]);
    foreach (range(1, 9) as $index) {
        createStoreCatalogProduct(ServiceType::Sbc, [
            'slug' => "related-sbc-{$index}",
            'sort_order' => $index,
        ]);
    }

    $this->get('/sbc/localized-sbc-product')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/catalog-product', false)
            ->where('locale', 'ar')
            ->where('catalog.product.slug', 'localized-sbc-product')
            ->has('catalog.suggestions', 8)
            ->where('catalog.suggestions', fn ($suggestions): bool => $suggestions
                ->doesntContain(fn (array $product): bool => $product['id'] === $current->public_id)));

    $this->get('/en/sbc/localized-sbc-product')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/catalog-product', false)
            ->where('locale', 'en')
            ->where('catalog.product.slug', 'localized-sbc-product')
            ->has('catalog.suggestions', 8));
});

test('SBC product pages expose only converted completion totals for each platform', function () {
    $product = createStoreCatalogProduct(ServiceType::Sbc, [
        'slug' => 'repeatable-player',
    ], [
        'platform' => Platform::PlayStation,
        'price_halalah' => 5_700,
        'configuration' => storeSbcCompletionPricing([
            ['completions' => 5, 'multiplierBps' => 10_000, 'totalMinor' => 5_700],
            ['completions' => 10, 'multiplierBps' => 9_500, 'totalMinor' => 10_830],
            ['completions' => 15, 'multiplierBps' => 9_200, 'totalMinor' => 15_732],
            ['completions' => 20, 'multiplierBps' => 9_000, 'totalMinor' => 20_520],
            ['completions' => 30, 'multiplierBps' => 8_700, 'totalMinor' => 29_754],
            ['completions' => 40, 'multiplierBps' => 8_500, 'totalMinor' => 38_760],
            ['completions' => 50, 'multiplierBps' => 8_200, 'totalMinor' => 46_740],
            ['completions' => 75, 'multiplierBps' => 7_800, 'totalMinor' => 66_690],
            ['completions' => 100, 'multiplierBps' => 7_600, 'totalMinor' => 86_640],
        ]),
    ]);
    ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::Pc,
        'price_halalah' => 6_300,
        'configuration' => storeSbcCompletionPricing([
            ['completions' => 5, 'multiplierBps' => 10_000, 'totalMinor' => 6_300],
            ['completions' => 10, 'multiplierBps' => 9_500, 'totalMinor' => 11_970],
            ['completions' => 15, 'multiplierBps' => 9_200, 'totalMinor' => 17_388],
            ['completions' => 20, 'multiplierBps' => 9_000, 'totalMinor' => 22_680],
            ['completions' => 30, 'multiplierBps' => 8_700, 'totalMinor' => 32_886],
            ['completions' => 40, 'multiplierBps' => 8_500, 'totalMinor' => 42_840],
            ['completions' => 50, 'multiplierBps' => 8_200, 'totalMinor' => 51_660],
            ['completions' => 75, 'multiplierBps' => 7_800, 'totalMinor' => 73_710],
            ['completions' => 100, 'multiplierBps' => 7_600, 'totalMinor' => 95_760],
        ]),
    ]);

    $this->get('/en/sbc/repeatable-player')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.product.variants.0.completionTiers.0', [
                'completions' => 5,
                'price' => ['amountMinor' => 5_700, 'currency' => 'SAR'],
            ])
            ->where('catalog.product.variants.0.completionTiers.1', [
                'completions' => 10,
                'price' => ['amountMinor' => 10_830, 'currency' => 'SAR'],
            ])
            ->where('catalog.product.variants.1.completionTiers.1', [
                'completions' => 10,
                'price' => ['amountMinor' => 11_970, 'currency' => 'SAR'],
            ])
            ->missing('catalog.product.variants.0.configuration')
            ->missing('catalog.product.variants.0.completionTiers.0.multiplierBps'));
});

test('legacy SBC variants expose one completion and malformed declared pricing fails closed', function () {
    createStoreCatalogProduct(ServiceType::Sbc, ['slug' => 'legacy-sbc'], [
        'price_halalah' => 4_200,
    ]);
    createStoreCatalogProduct(ServiceType::Sbc, ['slug' => 'malformed-sbc'], [
        'price_halalah' => 4_200,
        'configuration' => [
            'completionPricing' => ['version' => 1, 'repeatable' => true],
        ],
    ]);

    $this->get('/en/sbc/legacy-sbc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where(
            'catalog.product.variants.0.completionTiers',
            [['completions' => 1, 'price' => ['amountMinor' => 4_200, 'currency' => 'SAR']]],
        ));
    $this->get('/en/sbc/malformed-sbc')->assertNotFound();
});

test('homepage service rail contract has equal ordered internal routes and the exact Sell Coins destination', function () {
    $this->get('/en')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('homeContent.services', 5)
            ->where('homeContent.services.0.key', 'sbc')
            ->where('homeContent.services.0.href', '/en/sbc')
            ->where('homeContent.services.0.imageUrl', '/images/store/services/sbc.webp')
            ->where('homeContent.services.1.key', 'objectives')
            ->where('homeContent.services.1.href', '/en/objectives')
            ->where('homeContent.services.1.imageUrl', '/images/store/services/objectives.webp')
            ->where('homeContent.services.2.key', 'fut_champions')
            ->where('homeContent.services.2.href', '/en/fut-champions')
            ->where('homeContent.services.2.imageUrl', '/images/store/services/fut-champions.webp')
            ->where('homeContent.services.3.key', 'rivals')
            ->where('homeContent.services.3.href', '/en/rivals')
            ->where('homeContent.services.3.imageUrl', '/images/store/services/rivals.webp')
            ->where('homeContent.services.4.key', 'sell_coins')
            ->where('homeContent.services.4.href', 'https://sell.arab-ut.com/')
            ->where('homeContent.services.4.imageUrl', '/images/store/services/sell-coins.webp')
            ->where('homeContent.services.4.external', true));
});
