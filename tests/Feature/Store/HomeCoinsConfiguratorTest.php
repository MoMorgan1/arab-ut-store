<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function homeRuleConfiguration(string $group, array $changes = []): array
{
    $configuration = [
        'version' => 1,
        'group' => $group,
        'tier_upper_bounds_k' => [100, 500, 1000, 2000, 5000],
        'multipliers_basis_points' => ['50000' => 10_000],
        'service_fee_halalah' => 0,
        'discount_divisor_basis_points' => 10_000,
        'exact_overrides_halalah' => [],
    ];
    $configuration[$group === 'console_normal'
        ? 'flat_rate_halalah_per_million'
        : 'tier_rates_halalah_per_million'] = $group === 'console_normal'
            ? 5_000
            : array_fill(0, 6, 5_000);

    return array_replace($configuration, $changes);
}

function createHomeCatalog(): Product
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Coins,
        'name_ar' => 'كوينز ألتيميت تيم',
        'name_en' => 'Ultimate Team Coins',
        'is_visible' => true,
        'archived_at' => null,
    ]);

    foreach ([Platform::PlayStation, Platform::Pc] as $platform) {
        ProductVariant::factory()->for($product)->create([
            'service_type' => ServiceType::Coins,
            'platform' => $platform,
            'is_active' => true,
        ]);
    }

    foreach (['console_normal', 'console_fast', 'pc'] as $group) {
        PriceRule::create([
            'name' => "Coins {$group}",
            'service_type' => ServiceType::Coins,
            'configuration' => homeRuleConfiguration($group),
            'is_active' => true,
        ]);
    }

    return $product;
}

test('the Arabic and English homepages expose the exact localized Coins contract', function (string $path, string $name, string $quoteUrl) {
    $product = createHomeCatalog();

    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/home')
            ->where('status', 'available')
            ->where('product.publicId', $product->public_id)
            ->where('product.name', $name)
            ->where('product.imageUrl', '/images/store/coins/ut-coin-80.webp')
            ->where('quoteUrl', $quoteUrl)
            ->where('amount', [
                'minimum' => 50_000,
                'increment' => 10_000,
                'presets' => [50_000, 100_000, 500_000, 1_000_000],
            ])
            ->where('platforms.0.value', 'playstation')
            ->where('platforms.0.iconUrls', [
                '/images/store/platforms/ps-logo-white-80.webp',
                '/images/store/platforms/xbox-logo-white-80.webp',
            ])
            ->where('platforms.0.maximum', 20_000_000)
            ->where('platforms.0.deliveries.0.value', 'normal')
            ->where('platforms.0.deliveries.0.minutesPerMillion', 150)
            ->where('platforms.0.deliveries.1.value', 'fast')
            ->where('platforms.0.deliveries.1.minutesPerMillion', 45)
            ->where('platforms.1.value', 'pc')
            ->where('platforms.1.iconUrls', ['/images/store/platforms/pc-logo.svg'])
            ->where('platforms.1.maximum', 2_000_000)
            ->where('platforms.1.deliveries', [])
            ->has('platforms', 2)
            ->has('store.seo_title')
            ->missing('platforms.0.market')
            ->missing('platforms.1.market'));
})->with([
    'Arabic' => ['/', 'كوينز ألتيميت تيم', '/coins/quote'],
    'English' => ['/en', 'Ultimate Team Coins', '/en/coins/quote'],
]);

test('configured Coins media URLs resolve to public assets', function () {
    $urls = [
        config('coins.product_image_url'),
        ...collect(config('coins.platforms'))->flatMap(
            fn (array $platform): array => $platform['icon_urls'],
        )->all(),
    ];

    foreach ($urls as $url) {
        expect($url)->toBeString();
        expect(public_path(ltrim($url, '/')))->toBeFile();
    }
});

test('the homepage remains honest when local catalog or pricing is unavailable', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'unavailable')
            ->where('product', null)
            ->where('quoteUrl', '/coins/quote')
            ->has('platforms', 2)
            ->has('store.availability.title'));
});

test('the homepage fails closed when an active Coins pricing rule has an unknown group', function () {
    createHomeCatalog();
    PriceRule::create([
        'name' => 'Unknown Coins pricing',
        'service_type' => ServiceType::Coins,
        'configuration' => ['group' => 'legacy_console'],
        'is_active' => true,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'unavailable')
            ->where('product', null));
});

test('an optional Xbox Coins variant is not exposed as a separate homepage choice', function () {
    $product = createHomeCatalog();
    ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Coins,
        'platform' => Platform::Xbox,
        'is_active' => true,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'available')
            ->has('platforms', 2)
            ->where('platforms.0.value', 'playstation')
            ->where('platforms.1.value', 'pc'));
});

test('the homepage fails closed when either localized Coins product name is blank', function (
    string $field,
    string $value,
) {
    $product = createHomeCatalog();
    $product->update([$field => $value]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'unavailable')
            ->where('product', null));
})->with([
    'empty Arabic name' => ['name_ar', ''],
    'empty English name' => ['name_en', ''],
    'whitespace-only name' => ['name_ar', " \t\n"],
]);

test('the homepage fails closed when pricing does not cover the minimum legal quantity', function () {
    createHomeCatalog();

    PriceRule::query()->each(function (PriceRule $rule): void {
        $configuration = $rule->getAttribute('configuration');
        expect($configuration)->toBeArray();
        $configuration['multipliers_basis_points'] = ['100000' => 10_000];
        $rule->update(['configuration' => $configuration]);
    });

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'unavailable')
            ->where('product', null));
});

test('the homepage fails closed when a later legal quantity would overflow pricing', function () {
    createHomeCatalog();

    $fast = PriceRule::query()
        ->get()
        ->first(function (PriceRule $rule): bool {
            $configuration = $rule->getAttribute('configuration');

            return is_array($configuration) && ($configuration['group'] ?? null) === 'console_fast';
        });

    if (! $fast instanceof PriceRule) {
        throw new LogicException('The test catalog must contain a fast pricing rule.');
    }

    $fast->update([
        'configuration' => homeRuleConfiguration('console_fast', [
            'tier_rates_halalah_per_million' => array_fill(0, 6, 1_000_000_000),
        ]),
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'unavailable')
            ->where('product', null));
});

test('homepage props omit supplier market and forbidden policy proof and credential copy', function () {
    createHomeCatalog();

    $props = $this->get('/')->inertiaPage()['props'];
    $serialized = json_encode($props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($serialized)
        ->not->toContain('"market"')
        ->not->toContain('current balance')
        ->not->toContain('credentials')
        ->not->toContain('reviews')
        ->not->toContain('checkout currency');
});
