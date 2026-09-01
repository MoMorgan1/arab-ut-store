<?php

use App\Enums\Platform;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ServicePriceSchedule;
use Inertia\Testing\AssertableInertia as Assert;

function manualServiceCatalogMigration(): object
{
    return require database_path('migrations/2026_08_16_000003_provision_manual_service_catalog.php');
}

function nestedManualServiceKeys(array $value): array
{
    $keys = [];

    foreach ($value as $key => $nested) {
        if (is_string($key)) {
            $keys[] = strtolower($key);
        }

        if (is_array($nested)) {
            $keys = [...$keys, ...nestedManualServiceKeys($nested)];
        }
    }

    return $keys;
}

it('exposes the exact public FUT Champions service contract in both locales', function (string $uri, string $locale, string $addUrl) {
    $response = $this->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/manual-service', false)
            ->where('locale', $locale)
            ->where('manualService.service', 'fut_champions')
            ->where('manualService.active', true)
            ->where('manualService.scheduleVersion', 1)
            ->where('manualService.addUrl', $addUrl)
            ->where('manualService.platforms', ['playstation', 'pc'])
            ->where('manualService.tutorials.ea', 'https://youtube.com/shorts/hNIW1ps_t3k?si=i9MR5izDKRhpRNjo')
            ->where('manualService.tutorials.playstation', 'https://youtu.be/fCAKsusuHR8?si=cYzL6fwszL4ExwPK')
            ->where('manualService.product.slug', 'fut-champions')
            ->where('manualService.product.image.url', '/images/store/services/fut-champions.webp')
            ->where('manualService.pricing.currency', 'SAR')
            ->where('manualService.pricing.rankOptions', [
                ['rank' => 1, 'price' => ['amountMinor' => 22_000, 'currency' => 'SAR']],
                ['rank' => 2, 'price' => ['amountMinor' => 19_000, 'currency' => 'SAR']],
                ['rank' => 3, 'price' => ['amountMinor' => 17_000, 'currency' => 'SAR']],
                ['rank' => 4, 'price' => ['amountMinor' => 15_000, 'currency' => 'SAR']],
                ['rank' => 5, 'price' => ['amountMinor' => 13_000, 'currency' => 'SAR']],
                ['rank' => 6, 'price' => ['amountMinor' => 10_000, 'currency' => 'SAR']],
            ])
            ->where('manualService.pricing.urgentSurcharge', ['amountMinor' => 4_000, 'currency' => 'SAR'])
            ->where('manualServicePage.service.title', $locale === 'ar' ? 'خدمة لعب الفوت' : 'FUT Champions service')
            ->where('manualServicePage.service.urgent_eta', $locale === 'ar'
                ? 'المستعجل خلال 24–36 ساعة من استلام البيانات الصحيحة.'
                : 'Urgent orders take 24–36 hours from receiving the correct details.')
            ->where('manualServicePage.relatedServices.sbcUrl', $locale === 'ar' ? '/sbc' : '/en/sbc')
            ->where('manualServicePage.relatedServices.service.key', 'rivals')
            ->where('manualServicePage.relatedServices.service.href', $locale === 'ar' ? '/rivals' : '/en/rivals'));

    /** @var array<string, mixed> $props */
    $props = $response->viewData('page')['props']['manualService'];
    $keys = nestedManualServiceKeys($props);

    expect($keys)->not->toContain('xbox', 'credentials', 'password', 'codes', 'code', 'path');
})->with([
    'Arabic' => ['/fut-champions', 'ar', '/cart/items/fut-champions'],
    'English' => ['/en/fut-champions', 'en', '/en/cart/items/fut-champions'],
]);

it('exposes the exact public Rivals service contract in both locales', function (string $uri, string $locale, string $addUrl) {
    $this->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('store/manual-service', false)
            ->where('locale', $locale)
            ->where('manualService.service', 'rivals')
            ->where('manualService.active', true)
            ->where('manualService.scheduleVersion', 1)
            ->where('manualService.addUrl', $addUrl)
            ->where('manualService.platforms', ['playstation', 'pc'])
            ->where('manualService.tutorials.ea', 'https://youtube.com/shorts/hNIW1ps_t3k?si=i9MR5izDKRhpRNjo')
            ->where('manualService.tutorials.playstation', 'https://youtu.be/fCAKsusuHR8?si=cYzL6fwszL4ExwPK')
            ->where('manualService.product.slug', 'division-rivals')
            ->where('manualService.product.image.url', '/images/store/services/rivals.webp')
            ->where('manualService.pricing.currency', 'SAR')
            ->where('manualService.pricing.ladder', ['7', '6', '5', '4', '3', '2', '1', 'elite'])
            ->where('manualService.pricing.stepOptions', [
                ['from' => '7', 'to' => '6', 'price' => ['amountMinor' => 11_000, 'currency' => 'SAR']],
                ['from' => '6', 'to' => '5', 'price' => ['amountMinor' => 12_000, 'currency' => 'SAR']],
                ['from' => '5', 'to' => '4', 'price' => ['amountMinor' => 13_000, 'currency' => 'SAR']],
                ['from' => '4', 'to' => '3', 'price' => ['amountMinor' => 14_000, 'currency' => 'SAR']],
                ['from' => '3', 'to' => '2', 'price' => ['amountMinor' => 15_000, 'currency' => 'SAR']],
                ['from' => '2', 'to' => '1', 'price' => ['amountMinor' => 16_000, 'currency' => 'SAR']],
                ['from' => '1', 'to' => 'elite', 'price' => ['amountMinor' => 17_000, 'currency' => 'SAR']],
            ])
            ->where('manualServicePage.service.title', $locale === 'ar' ? 'خدمة الرايفلز' : 'Division Rivals service')
            ->where('manualServicePage.service.standard_eta', $locale === 'ar'
                ? 'يستغرق الطلب عادةً من يوم إلى 3 أيام حسب ضغط الطلبات وعدد الديفجنات المطلوبة.'
                : 'Orders usually take 1–3 days, depending on demand and the number of divisions requested.')
            ->where('manualServicePage.relatedServices.sbcUrl', $locale === 'ar' ? '/sbc' : '/en/sbc')
            ->where('manualServicePage.relatedServices.service.key', 'fut_champions')
            ->where('manualServicePage.relatedServices.service.href', $locale === 'ar' ? '/fut-champions' : '/en/fut-champions'));
})->with([
    'Arabic' => ['/rivals', 'ar', '/cart/items/rivals'],
    'English' => ['/en/rivals', 'en', '/en/cart/items/rivals'],
]);

it('exposes up to 8 real public SBC products in relatedServices and keeps internal fields hidden', function () {
    for ($i = 1; $i <= 5; $i++) {
        $product = Product::factory()->create([
            'service_type' => ServiceType::Sbc,
            'slug' => "sbc-product-{$i}",
            'name_ar' => "تحدي {$i}",
            'name_en' => "SBC Challenge {$i}",
            'description_ar' => "وصف تحدي {$i}",
            'description_en' => "Description for challenge {$i}",
            'is_visible' => true,
            'archived_at' => null,
            'sort_order' => $i,
        ]);

        ProductVariant::factory()->for($product)->create([
            'service_type' => ServiceType::Sbc,
            'platform' => Platform::PlayStation,
            'price_halalah' => 10_000 * $i,
            'is_active' => true,
        ]);
    }

    $response = $this->get('/fut-champions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('manualServicePage.relatedServices.products', 5)
            ->where('manualServicePage.relatedServices.sbcUrl', '/sbc')
            ->where('manualServicePage.relatedServices.service.key', 'rivals')
            ->where('manualServicePage.relatedServices.products.0.name', 'تحدي 1')
            ->where('manualServicePage.relatedServices.products.0.url', '/sbc/sbc-product-1')
            ->where('manualServicePage.relatedServices.products.0.price.amountMinor', 10_000)
            ->where('manualServicePage.relatedServices.products.0.price.currency', 'SAR')
            ->has('manualServicePage.relatedServices.products.0.variants', 1)
            ->where('manualServicePage.relatedServices.products.0.slug', 'sbc-product-1')
            ->where('manualServicePage.relatedServices.products.4.name', 'تحدي 5')
        );

    $relatedProducts = $response->viewData('page')['props']['manualServicePage']['relatedServices']['products'];
    $keys = nestedManualServiceKeys($relatedProducts);

    expect($keys)->not->toContain('cost', 'internal', 'supplier', 'margin', 'profit', 'stock');
});

it('returns an empty products list when no SBC product is public', function () {
    Product::query()->where('service_type', ServiceType::Sbc)->delete();

    $this->get('/fut-champions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manualServicePage.relatedServices.products', [])
            ->where('manualServicePage.relatedServices.sbcUrl', '/sbc')
            ->where('manualServicePage.relatedServices.service.key', 'rivals')
        );
});

it('renders an unavailable state instead of publishing stale manual-service prices', function (ServiceType $service, string $uri) {
    ServicePriceSchedule::query()->where('service_type', $service)->update(['is_active' => false]);

    $this->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manualService.active', false)
            ->where('manualService.pricing', null));
})->with([
    'FUT Champions' => [ServiceType::FutChampions, '/fut-champions'],
    'Rivals' => [ServiceType::Rivals, '/rivals'],
]);

it('provisions stable manual product and platform identities idempotently', function () {
    $fut = Product::query()->where('slug', 'fut-champions')->sole();
    $rivals = Product::query()->where('slug', 'division-rivals')->sole();

    expect($fut->service_type)->toBe(ServiceType::FutChampions)
        ->and($fut->authority)->toBe(ProductAuthority::Manual)
        ->and($fut->variants()->pluck('platform')->all())->toEqualCanonicalizing([
            Platform::PlayStation,
            Platform::Pc,
        ])
        ->and($rivals->service_type)->toBe(ServiceType::Rivals)
        ->and($rivals->authority)->toBe(ProductAuthority::Manual)
        ->and($rivals->variants()->pluck('platform')->all())->toEqualCanonicalizing([
            Platform::PlayStation,
            Platform::Pc,
        ]);

    manualServiceCatalogMigration()->up();

    expect(Product::query()->whereIn('slug', ['fut-champions', 'division-rivals'])->count())->toBe(2)
        ->and(ProductVariant::query()->whereIn('sku', [
            'MANUAL_FUT_CHAMPIONS_PLAYSTATION',
            'MANUAL_FUT_CHAMPIONS_PC',
            'MANUAL_RIVALS_PLAYSTATION',
            'MANUAL_RIVALS_PC',
        ])->count())->toBe(4);
});

it('rejects an automation-owned manual-service slug conflict', function () {
    Product::query()->where('slug', 'fut-champions')->delete();
    Product::factory()->create([
        'category_id' => null,
        'slug' => 'fut-champions',
        'service_type' => ServiceType::FutChampions,
        'authority' => ProductAuthority::Automation,
    ]);

    expect(fn () => manualServiceCatalogMigration()->up())
        ->toThrow(RuntimeException::class, 'fut-champions');
});

it('rolls back only catalog rows created by the manual-service migration', function () {
    $migration = manualServiceCatalogMigration();
    $migration->down();

    $existing = Product::factory()->create([
        'category_id' => null,
        'slug' => 'fut-champions',
        'service_type' => ServiceType::FutChampions,
        'authority' => ProductAuthority::Manual,
    ]);

    $migration->up();
    expect($existing->fresh())->not->toBeNull()
        ->and($existing->fresh()->variants)->toHaveCount(2);

    $migration->down();
    expect($existing->fresh())->not->toBeNull()
        ->and($existing->fresh()->variants)->toHaveCount(0);

    $migration->up();
});
