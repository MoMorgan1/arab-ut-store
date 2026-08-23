<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function (): void {
    Carbon::setTestNow();
});

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00', 'UTC'));
});

/** @param  array<string, mixed>  $overrides */
function promotedCatalogProduct(array $overrides = []): Product
{
    $product = Product::factory()->create(array_merge([
        'service_type' => ServiceType::Objectives,
        'slug' => fake()->unique()->slug(),
        'name_ar' => 'خدمة العروض',
        'name_en' => 'Promoted service',
        'is_visible' => true,
        'archived_at' => null,
    ], $overrides));

    ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Objectives,
        'platform' => Platform::PlayStation,
        'price_halalah' => 10_000,
        'sale_price_halalah' => null,
        'is_active' => true,
        'configuration' => [],
    ]);

    return $product->refresh();
}

test('catalog pages publish promoted prices with a struck-through base price and a localized badge', function (): void {
    promotedCatalogProduct(['slug' => 'promoted-one']);
    Promotion::query()->create([
        'name_ar' => 'عرض الصيف',
        'name_en' => 'Summer deal',
        'badge_ar' => 'خصم 20%',
        'badge_en' => '20% off',
        'scope' => Promotion::SCOPE_ALL,
        'discount_type' => 'percent',
        'value' => 20,
        'is_active' => true,
    ]);

    $this->get('/objectives')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.products.0.price.amountMinor', 8_000)
            ->where('catalog.products.0.compareAtPrice.amountMinor', 10_000)
            ->where('catalog.products.0.promotionBadge', 'خصم 20%')
            ->where('catalog.products.0.variants.0.price.amountMinor', 8_000)
            ->where('catalog.products.0.variants.0.compareAtPrice.amountMinor', 10_000)
            ->where('catalog.products.0.variants.0.promotionBadge', 'خصم 20%'));

    $this->get('/en/objectives')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.products.0.price.amountMinor', 8_000)
            ->where('catalog.products.0.promotionBadge', '20% off'));
});

test('category scoped promotions apply only to products inside the category', function (): void {
    $category = Category::factory()->create();
    promotedCatalogProduct(['slug' => 'in-category', 'category_id' => $category->id]);
    promotedCatalogProduct(['slug' => 'outside-category', 'category_id' => null]);
    Promotion::query()->create([
        'name_ar' => 'عرض الفئة',
        'name_en' => 'Category deal',
        'badge_ar' => 'خصم 30%',
        'badge_en' => '30% off',
        'scope' => Promotion::SCOPE_CATEGORY,
        'category_id' => $category->id,
        'discount_type' => 'percent',
        'value' => 30,
        'is_active' => true,
    ]);

    $this->get('/en/objectives')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.products.0.slug', 'in-category')
            ->where('catalog.products.0.price.amountMinor', 7_000)
            ->where('catalog.products.0.promotionBadge', '30% off')
            ->where('catalog.products.1.slug', 'outside-category')
            ->where('catalog.products.1.price.amountMinor', 10_000)
            ->where('catalog.products.1.compareAtPrice', null)
            ->where('catalog.products.1.promotionBadge', null));
});

test('service scoped promotions follow the product service type', function (): void {
    promotedCatalogProduct(['slug' => 'objective-promoted']);
    Promotion::query()->create([
        'name_ar' => 'عرض الأوبجكتيف',
        'name_en' => 'Objectives deal',
        'badge_ar' => null,
        'badge_en' => '15% off',
        'scope' => Promotion::SCOPE_SERVICE,
        'service_type' => ServiceType::Objectives->value,
        'discount_type' => 'percent',
        'value' => 15,
        'is_active' => true,
    ]);

    $this->get('/en/objectives')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.products.0.price.amountMinor', 8_500)
            ->where('catalog.products.0.variants.0.promotionBadge', '15% off'));

    $this->get('/sbc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('catalog.products', 0));
});

test('inactive and out-of-window promotions leave catalog prices untouched', function (): void {
    promotedCatalogProduct(['slug' => 'calm-catalog']);
    Promotion::query()->create([
        'name_ar' => 'عرض موقوف',
        'name_en' => 'Paused deal',
        'badge_ar' => 'خصم 40%',
        'badge_en' => '40% off',
        'scope' => Promotion::SCOPE_ALL,
        'discount_type' => 'percent',
        'value' => 40,
        'is_active' => false,
    ]);
    Promotion::query()->create([
        'name_ar' => 'عرض منتهي',
        'name_en' => 'Ended deal',
        'badge_ar' => 'خصم 50%',
        'badge_en' => '50% off',
        'scope' => Promotion::SCOPE_ALL,
        'discount_type' => 'percent',
        'value' => 50,
        'is_active' => true,
        'starts_at' => Carbon::parse('2026-08-01 00:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-08-20 00:00:00', 'UTC'),
    ]);

    $this->get('/en/objectives')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.products.0.price.amountMinor', 10_000)
            ->where('catalog.products.0.compareAtPrice', null)
            ->where('catalog.products.0.promotionBadge', null));

    Carbon::setTestNow(Carbon::parse('2026-08-05 00:00:00', 'UTC'));

    $this->get('/en/objectives')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.products.0.price.amountMinor', 5_000)
            ->where('catalog.products.0.promotionBadge', '50% off'));
});
