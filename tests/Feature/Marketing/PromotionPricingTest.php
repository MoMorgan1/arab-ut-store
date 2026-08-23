<?php

use App\Enums\ServiceType;
use App\Marketing\PromotionPricing;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Support\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00', 'UTC'));
});

function resolverPromotionAttributes(array $overrides = []): array
{
    return array_merge([
        'name_ar' => 'عرض تجريبي',
        'name_en' => 'Test promotion',
        'badge_ar' => 'خصم 20%',
        'badge_en' => '20% off',
        'scope' => Promotion::SCOPE_ALL,
        'discount_type' => 'percent',
        'value' => 20,
        'is_active' => true,
    ], $overrides);
}

test('an all-scope active promotion discounts every service with a floored price', function (): void {
    Promotion::query()->create(resolverPromotionAttributes(['value' => 15]));
    $resolver = new PromotionPricing;

    $price = $resolver->resolve(null, ServiceType::Coins, 999);

    expect($price)->not->toBeNull()
        ->and($price?->discountHalalah)->toBe(149)
        ->and($price?->discountedHalalah)->toBe(850)
        ->and($price->promotion->scope)->toBe('all');
});

test('a category-scoped promotion only applies to that category', function (): void {
    $category = Category::factory()->create();
    $other = Category::factory()->create();
    Promotion::query()->create(resolverPromotionAttributes([
        'scope' => Promotion::SCOPE_CATEGORY,
        'category_id' => $category->id,
        'badge_ar' => null,
        'badge_en' => null,
    ]));
    $resolver = new PromotionPricing;

    expect($resolver->resolve($category->id, ServiceType::Sbc, 10_000))->not->toBeNull()
        ->and($resolver->resolve($other->id, ServiceType::Sbc, 10_000))->toBeNull()
        ->and($resolver->resolve(null, ServiceType::Sbc, 10_000))->toBeNull();
});

test('a service-scoped promotion only applies to that service type', function (): void {
    Promotion::query()->create(resolverPromotionAttributes([
        'scope' => Promotion::SCOPE_SERVICE,
        'service_type' => ServiceType::FutChampions->value,
    ]));
    $resolver = new PromotionPricing;

    expect($resolver->resolve(null, ServiceType::FutChampions, 20_000))->not->toBeNull()
        ->and($resolver->resolve(null, ServiceType::Rivals, 20_000))->toBeNull();
});

test('a product-scoped promotion only applies to that product', function (): void {
    $product = Product::factory()->create();
    $other = Product::factory()->create();
    Promotion::query()->create(resolverPromotionAttributes([
        'scope' => Promotion::SCOPE_PRODUCT,
        'product_id' => $product->id,
    ]));
    $resolver = new PromotionPricing;

    expect($resolver->resolve(null, ServiceType::Sbc, 10_000, $product->id))->not->toBeNull()
        ->and($resolver->resolve(null, ServiceType::Sbc, 10_000, $other->id))->toBeNull()
        ->and($resolver->resolve(null, ServiceType::Sbc, 10_000, null))->toBeNull();
});

test('the largest discount wins across overlapping promotions', function (): void {
    $percent = Promotion::query()->create(resolverPromotionAttributes(['value' => 10]))->refresh();
    $fixed = Promotion::query()->create(resolverPromotionAttributes([
        'name_ar' => 'خصم ثابت',
        'name_en' => 'Fixed off',
        'badge_ar' => 'خصم 5 ر.س',
        'badge_en' => '5 SAR off',
        'discount_type' => 'fixed',
        'value' => 500,
    ]))->refresh();
    $smaller = Promotion::query()->create(resolverPromotionAttributes(['value' => 5]))->refresh();
    $resolver = new PromotionPricing;

    $price = $resolver->resolve(null, ServiceType::Sbc, 4_000);

    expect($price?->promotion->id)->toBe((int) $fixed->id)
        ->and($price?->baseHalalah)->toBe(4_000)
        ->and($price?->discountHalalah)->toBe(500)
        ->and($price?->discountedHalalah)->toBe(3_500)
        ->and($percent->exists)->toBeTrue()
        ->and($smaller->exists)->toBeTrue();
});

test('the promotion window bounds are inclusive and inactive promotions never apply', function (): void {
    Promotion::query()->create(resolverPromotionAttributes([
        'starts_at' => Carbon::parse('2026-08-23 12:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-08-23 12:00:00', 'UTC'),
    ]));

    expect((new PromotionPricing)->resolve(null, ServiceType::Sbc, 1_000))->not->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:01', 'UTC'));

    expect((new PromotionPricing)->resolve(null, ServiceType::Sbc, 1_000))->toBeNull();

    Promotion::query()->update(['ends_at' => null, 'starts_at' => null, 'is_active' => false]);

    expect((new PromotionPricing)->resolve(null, ServiceType::Sbc, 1_000))->toBeNull();
});

test('fixed discounts are capped at the base price which is never discounted below zero', function (): void {
    Promotion::query()->create(resolverPromotionAttributes([
        'discount_type' => 'fixed',
        'value' => 100_000,
    ]));
    $resolver = new PromotionPricing;

    $price = $resolver->resolve(null, ServiceType::Objectives, 900);

    expect($price?->discountHalalah)->toBe(900)
        ->and($price?->discountedHalalah)->toBe(0);
});

test('zero and negative base prices never resolve a promotion', function (): void {
    Promotion::query()->create(resolverPromotionAttributes());
    $resolver = new PromotionPricing;

    expect($resolver->resolve(null, ServiceType::Sbc, 0))->toBeNull();
});
