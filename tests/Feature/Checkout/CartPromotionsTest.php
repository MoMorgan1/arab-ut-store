<?php

use App\Actions\Checkout\ApplyCoupon;
use App\Actions\Checkout\PlaceOrder;
use App\Checkout\DiscountEngine;
use App\Checkout\DiscountLine;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Exceptions\Checkout\CartRepriced;
use App\Marketing\PromotionPricing;
use App\Models\Cart;
use App\Models\CartItemSecret;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\PromotionComponent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

afterEach(function (): void {
    Carbon::setTestNow();
});

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'UTC'));
});

/* ------------------------------------------------------------------ *
 * Helper Fixtures
 * ------------------------------------------------------------------ */

function makeCartPromoUser(array $overrides = []): User
{
    static $seq = 5000;
    $seq++;

    return User::factory()->create(array_merge([
        'phone' => '+9665'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT),
        'phone_verified_at' => now(),
    ], $overrides));
}

function makeCartPromoProduct(
    ServiceType $serviceType = ServiceType::Sbc,
    int $priceHalalah = 10_000,
    ?Category $category = null,
    array $productOverrides = [],
    array $variantOverrides = [],
): array {
    $product = Product::factory()->create(array_merge([
        'category_id' => $category?->id,
        'service_type' => $serviceType,
        'name_ar' => 'خدمة تجريبية',
        'name_en' => 'Test Service',
        'is_visible' => true,
        'archived_at' => null,
    ], $productOverrides));

    $variant = ProductVariant::factory()->for($product)->create(array_merge([
        'service_type' => $serviceType,
        'platform' => Platform::PlayStation,
        'price_halalah' => $priceHalalah,
        'sale_price_halalah' => null,
        'price_version' => 1,
        'is_active' => true,
    ], $variantOverrides));

    return [$product, $variant];
}

function makeCartWithMultipleLines(User $user, array $variantsWithPrices): array
{
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);

    $items = [];
    foreach ($variantsWithPrices as $entry) {
        /** @var ProductVariant $variant */
        $variant = $entry['variant'];
        $price = $entry['price'] ?? (int) $variant->price_halalah;

        $item = $cart->items()->create([
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price_halalah' => $price,
            'total_halalah' => $price,
            'configuration' => [
                'service_type' => $variant->service_type->value,
                'platform' => $variant->platform->value,
                'market' => 'console',
                'completion_count' => 1,
                'quoted_at' => now()->utc()->toIso8601String(),
                'price_version' => $variant->price_version,
            ],
        ]);

        $secret = new CartItemSecret([
            'cart_item_id' => $item->id,
            'masked_summary' => ['has_password' => true, 'backup_code_count' => 3],
            'retained_until' => null,
            'deleted_at' => null,
        ]);
        $secret->encrypted_payload = [
            'ea_email' => "user-{$user->id}-item-{$item->id}@example.test",
            'ea_password' => 'secret-password',
            'backup_codes' => ['11111111', '22222222', '33333333'],
        ];
        $secret->save();

        $items[] = $item;
    }

    return [$cart, $items];
}

/* ------------------------------------------------------------------ *
 * nth_item Tests
 * ------------------------------------------------------------------ */

test('buy 1 get next 50 percent discounts exactly one line the cheapest by half in a 3-line cart', function (): void {
    $category = Category::factory()->create();
    [$productA, $variantA] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);
    [$productB, $variantB] = makeCartPromoProduct(priceHalalah: 8_000, category: $category);
    [$productC, $variantC] = makeCartPromoProduct(priceHalalah: 6_000, category: $category);

    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'اشتري 1 واحصل على الثاني بنصف السعر',
        'name_en' => 'Buy 1 get next 50% off',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_NTH_ITEM,
        'buy_quantity' => 1,
        'get_quantity' => 1,
        'discount_target' => Promotion::TARGET_CHEAPEST,
        'discount_type' => 'percent',
        'value' => 50,
        'is_active' => true,
    ]);

    $lines = [
        new DiscountLine('line-1', $category->id, $productA->id, ServiceType::Sbc, 10_000),
        new DiscountLine('line-2', $category->id, $productB->id, ServiceType::Sbc, 8_000),
        new DiscountLine('line-3', $category->id, $productC->id, ServiceType::Sbc, 6_000),
    ];

    $engine = app(DiscountEngine::class);
    $result = $engine->calculate($lines);

    // 3 lines form 1 group of 2 (6,000 and 8,000). Cheapest line in group (6,000) is discounted 50% = 3,000.
    // 10,000 remains full price.
    // Base = 24,000. Promoted = 21,000. Total discount = 3,000.
    expect($result->baseSubtotalHalalah)->toBe(24_000)
        ->and($result->promotedSubtotalHalalah)->toBe(21_000)
        ->and($result->totalDiscountHalalah)->toBe(3_000)
        ->and($result->payableTotalHalalah)->toBe(21_000)
        ->and($result->linePromotionDiscounts['line-3'])->toBe(3_000)
        ->and($result->linePromotionDiscounts['line-2'])->toBe(0)
        ->and($result->linePromotionDiscounts['line-1'])->toBe(0);
});

test('max_applications = 2 on a 6-line cart discounts two lines not three', function (): void {
    $category = Category::factory()->create();
    [$product, $variant] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);

    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'اشتري 1 واحصل على الثاني بخصم 50% حد أقصى مرتين',
        'name_en' => 'Buy 1 get 1 50% max 2',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_NTH_ITEM,
        'buy_quantity' => 1,
        'get_quantity' => 1,
        'max_applications' => 2,
        'discount_target' => Promotion::TARGET_CHEAPEST,
        'discount_type' => 'percent',
        'value' => 50,
        'is_active' => true,
    ]);

    $lines = [
        new DiscountLine('line-1', $category->id, $product->id, ServiceType::Sbc, 10_000),
        new DiscountLine('line-2', $category->id, $product->id, ServiceType::Sbc, 9_000),
        new DiscountLine('line-3', $category->id, $product->id, ServiceType::Sbc, 8_000),
        new DiscountLine('line-4', $category->id, $product->id, ServiceType::Sbc, 7_000),
        new DiscountLine('line-5', $category->id, $product->id, ServiceType::Sbc, 6_000),
        new DiscountLine('line-6', $category->id, $product->id, ServiceType::Sbc, 5_000),
    ];

    $engine = app(DiscountEngine::class);
    $result = $engine->calculate($lines);

    // 6 lines with buy 1 get 1 could form 3 groups, but max_applications = 2 caps it to 2 groups.
    // 2 discounted lines instead of 3.
    $discountedCount = collect($result->linePromotionDiscounts)->filter(fn (int $d): bool => $d > 0)->count();
    expect($discountedCount)->toBe(2);
});

test('discount_target = most_expensive discounts the dearest of the group instead', function (): void {
    $category = Category::factory()->create();
    [$productA, $variantA] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);
    [$productB, $variantB] = makeCartPromoProduct(priceHalalah: 8_000, category: $category);
    [$productC, $variantC] = makeCartPromoProduct(priceHalalah: 6_000, category: $category);

    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'خصم الأغلى 50%',
        'name_en' => 'Discount dearest 50%',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_NTH_ITEM,
        'buy_quantity' => 1,
        'get_quantity' => 1,
        'discount_target' => Promotion::TARGET_MOST_EXPENSIVE,
        'discount_type' => 'percent',
        'value' => 50,
        'is_active' => true,
    ]);

    $lines = [
        new DiscountLine('line-1', $category->id, $productA->id, ServiceType::Sbc, 10_000),
        new DiscountLine('line-2', $category->id, $productB->id, ServiceType::Sbc, 8_000),
        new DiscountLine('line-3', $category->id, $productC->id, ServiceType::Sbc, 6_000),
    ];

    $engine = app(DiscountEngine::class);
    $result = $engine->calculate($lines);

    // Group of 2 from top: [10,000, 8,000]. Dearest in group is 10,000 -> 50% discount = 5,000.
    expect($result->totalDiscountHalalah)->toBe(5_000)
        ->and($result->linePromotionDiscounts['line-1'])->toBe(5_000)
        ->and($result->linePromotionDiscounts['line-2'])->toBe(0)
        ->and($result->linePromotionDiscounts['line-3'])->toBe(0);
});

test('qualifying_scope = same_product does not fire across two different SBCs while same_category does', function (): void {
    $category = Category::factory()->create();
    [$productA, $variantA] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);
    [$productB, $variantB] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);

    $lines = [
        new DiscountLine('line-a', $category->id, $productA->id, ServiceType::Sbc, 10_000),
        new DiscountLine('line-b', $category->id, $productB->id, ServiceType::Sbc, 10_000),
    ];

    // Promotion with same_product
    $promoSameProduct = Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض نفس المنتج',
        'name_en' => 'Same product promo',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_NTH_ITEM,
        'qualifying_scope' => Promotion::QUALIFYING_SCOPE_SAME_PRODUCT,
        'buy_quantity' => 1,
        'get_quantity' => 1,
        'discount_type' => 'percent',
        'value' => 50,
        'is_active' => true,
    ]);

    $engine = app(DiscountEngine::class);
    $resultProductScope = $engine->calculate($lines);

    // 2 different products each have 1 line (< 2 required for same_product). Discount must be 0!
    expect($resultProductScope->totalDiscountHalalah)->toBe(0);

    // Now change to same_category
    $promoSameProduct->update([
        'qualifying_scope' => Promotion::QUALIFYING_SCOPE_SAME_CATEGORY,
    ]);

    $engineFresh = new DiscountEngine(new PromotionPricing);
    $resultCategoryScope = $engineFresh->calculate($lines);

    // Both lines share the category -> 2 lines satisfy buy 1 get 1 -> 5,000 discount!
    expect($resultCategoryScope->totalDiscountHalalah)->toBe(5_000);
});

/* ------------------------------------------------------------------ *
 * Bundle Tests
 * ------------------------------------------------------------------ */

test('a bundle applies only when every component is present and adding one component short leaves the cart at full price', function (): void {
    [$productA, $variantA] = makeCartPromoProduct(priceHalalah: 10_000);
    [$productB, $variantB] = makeCartPromoProduct(priceHalalah: 10_000);

    // Bundle: Product A (qty 1) + Product B (qty 1) for 12,000 halalah (savings = 8,000)
    $bundle = Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'باقة التحديات',
        'name_en' => 'SBC Bundle',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_BUNDLE,
        'bundle_price_halalah' => 12_000,
        'discount_type' => 'fixed',
        'value' => 0,
        'is_active' => true,
    ]);
    PromotionComponent::query()->create([
        'public_id' => (string) Str::ulid(),
        'promotion_id' => $bundle->id,
        'product_id' => $productA->id,
        'quantity' => 1,
    ]);
    PromotionComponent::query()->create([
        'public_id' => (string) Str::ulid(),
        'promotion_id' => $bundle->id,
        'product_id' => $productB->id,
        'quantity' => 1,
    ]);

    $engine = app(DiscountEngine::class);

    // Cart with only Product A (missing Product B)
    $linesShort = [
        new DiscountLine('line-1', null, $productA->id, ServiceType::Sbc, 10_000),
    ];
    $resultShort = $engine->calculate($linesShort);
    expect($resultShort->totalDiscountHalalah)->toBe(0)
        ->and($resultShort->payableTotalHalalah)->toBe(10_000);

    // Cart with both Product A and Product B
    $linesComplete = [
        new DiscountLine('line-1', null, $productA->id, ServiceType::Sbc, 10_000),
        new DiscountLine('line-2', null, $productB->id, ServiceType::Sbc, 10_000),
    ];
    $resultComplete = (new DiscountEngine(new PromotionPricing))->calculate($linesComplete);
    expect($resultComplete->totalDiscountHalalah)->toBe(8_000)
        ->and($resultComplete->payableTotalHalalah)->toBe(12_000);
});

test('a bundle whose parts already total less than bundle_price_halalah does not apply', function (): void {
    [$productA, $variantA] = makeCartPromoProduct(priceHalalah: 3_000);
    [$productB, $variantB] = makeCartPromoProduct(priceHalalah: 3_000);

    // Parts total = 6,000 halalah. Bundle price = 8,000 halalah.
    $bundle = Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'باقة باهظة',
        'name_en' => 'Expensive Bundle',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_BUNDLE,
        'bundle_price_halalah' => 8_000,
        'discount_type' => 'fixed',
        'value' => 0,
        'is_active' => true,
    ]);
    PromotionComponent::query()->create([
        'public_id' => (string) Str::ulid(),
        'promotion_id' => $bundle->id,
        'product_id' => $productA->id,
        'quantity' => 1,
    ]);
    PromotionComponent::query()->create([
        'public_id' => (string) Str::ulid(),
        'promotion_id' => $bundle->id,
        'product_id' => $productB->id,
        'quantity' => 1,
    ]);

    $lines = [
        new DiscountLine('line-1', null, $productA->id, ServiceType::Sbc, 3_000),
        new DiscountLine('line-2', null, $productB->id, ServiceType::Sbc, 3_000),
    ];

    $engine = app(DiscountEngine::class);
    $result = $engine->calculate($lines);

    // Bundle must NOT apply — parts remain at 6,000 full price and are never increased.
    expect($result->totalDiscountHalalah)->toBe(0)
        ->and($result->payableTotalHalalah)->toBe(6_000);
});

test('bundle allocation across 3 components sums exactly to the discount including a case that does not divide evenly', function (): void {
    [$productA, $variantA] = makeCartPromoProduct(priceHalalah: 1_000);
    [$productB, $variantB] = makeCartPromoProduct(priceHalalah: 1_000);
    [$productC, $variantC] = makeCartPromoProduct(priceHalalah: 1_000);

    // Parts = 3,000. Bundle price = 2,900. Discount = 100 halalah.
    // 100 / 3 gives remainder 1. Largest remainder gives 34 + 33 + 33 = 100 exactly.
    $bundle = Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'باقة ثلاثية',
        'name_en' => 'Trio Bundle',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_BUNDLE,
        'bundle_price_halalah' => 2_900,
        'discount_type' => 'fixed',
        'value' => 0,
        'is_active' => true,
    ]);
    PromotionComponent::query()->create([
        'public_id' => (string) Str::ulid(),
        'promotion_id' => $bundle->id,
        'product_id' => $productA->id,
        'quantity' => 1,
    ]);
    PromotionComponent::query()->create([
        'public_id' => (string) Str::ulid(),
        'promotion_id' => $bundle->id,
        'product_id' => $productB->id,
        'quantity' => 1,
    ]);
    PromotionComponent::query()->create([
        'public_id' => (string) Str::ulid(),
        'promotion_id' => $bundle->id,
        'product_id' => $productC->id,
        'quantity' => 1,
    ]);

    $lines = [
        new DiscountLine('line-1', null, $productA->id, ServiceType::Sbc, 1_000),
        new DiscountLine('line-2', null, $productB->id, ServiceType::Sbc, 1_000),
        new DiscountLine('line-3', null, $productC->id, ServiceType::Sbc, 1_000),
    ];

    $engine = app(DiscountEngine::class);
    $result = $engine->calculate($lines);

    expect($result->totalDiscountHalalah)->toBe(100)
        ->and($result->payableTotalHalalah)->toBe(2_900)
        ->and(array_sum($result->linePromotionDiscounts))->toBe(100)
        ->and($result->linePromotionDiscounts['line-1'] + $result->linePromotionDiscounts['line-2'] + $result->linePromotionDiscounts['line-3'])->toBe(100);
});

/* ------------------------------------------------------------------ *
 * Competition, Non-Stacking & Promotion Precedence Tests
 * ------------------------------------------------------------------ */

test('when a bundle and an nth_item offer both match exactly one applies the larger and the totals prove the other did not', function (): void {
    [$productA, $variantA] = makeCartPromoProduct(priceHalalah: 10_000);
    [$productB, $variantB] = makeCartPromoProduct(priceHalalah: 10_000);

    // Offer 1: nth_item buy 1 get 1 50% -> saves 5,000 halalah
    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض اشتر 1 واحصل على 1 بخصم 50%',
        'name_en' => 'Buy 1 get 1 50% off',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_NTH_ITEM,
        'buy_quantity' => 1,
        'get_quantity' => 1,
        'discount_type' => 'percent',
        'value' => 50,
        'is_active' => true,
    ]);

    // Offer 2: Bundle of A + B for 12,000 halalah -> saves 8,000 halalah
    $bundle = Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'باقة ممتازة',
        'name_en' => 'Great Bundle',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_BUNDLE,
        'bundle_price_halalah' => 12_000,
        'discount_type' => 'fixed',
        'value' => 0,
        'is_active' => true,
    ]);
    PromotionComponent::query()->create([
        'public_id' => (string) Str::ulid(),
        'promotion_id' => $bundle->id,
        'product_id' => $productA->id,
        'quantity' => 1,
    ]);
    PromotionComponent::query()->create([
        'public_id' => (string) Str::ulid(),
        'promotion_id' => $bundle->id,
        'product_id' => $productB->id,
        'quantity' => 1,
    ]);

    $lines = [
        new DiscountLine('line-1', null, $productA->id, ServiceType::Sbc, 10_000),
        new DiscountLine('line-2', null, $productB->id, ServiceType::Sbc, 10_000),
    ];

    $engine = app(DiscountEngine::class);
    $result = $engine->calculate($lines);

    // Bundle saves 8,000 > nth_item saves 5,000. Exactly 8,000 applies (never 8,000 + 5,000 = 13,000).
    expect($result->totalDiscountHalalah)->toBe(8_000)
        ->and($result->payableTotalHalalah)->toBe(12_000);
});

test('a zero on buy, get or max_applications turns the offer off rather than making it unlimited', function (string $column): void {
    $category = Category::factory()->create();
    [$productA] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);
    [$productB] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);

    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض صفري',
        'name_en' => 'Zeroed offer',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_NTH_ITEM,
        'buy_quantity' => 1,
        'get_quantity' => 1,
        'max_applications' => 1,
        'discount_type' => 'percent',
        'value' => 100,
        'is_active' => true,
        $column => 0,
    ]);

    $lines = [
        new DiscountLine('line-1', $category->id, $productA->id, ServiceType::Sbc, 10_000),
        new DiscountLine('line-2', $category->id, $productB->id, ServiceType::Sbc, 10_000),
    ];

    $result = app(DiscountEngine::class)->calculate($lines);

    // A 100%-off offer that is switched off must give away nothing. Coercing the
    // zero to a default here would comp a whole line.
    expect($result->totalDiscountHalalah)->toBe(0)
        ->and($result->payableTotalHalalah)->toBe(20_000);
})->with(['buy_quantity', 'get_quantity', 'max_applications']);

test('a percent coupon above 100 is clamped instead of comping the whole order', function (): void {
    [$product] = makeCartPromoProduct(priceHalalah: 10_000);

    // Written past the form request - a seeder, a console command or an import can
    // do this, and sqlite carries no CHECK constraint to stop it.
    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'FATFINGER',
        'discount_type' => 'percent',
        'value' => 150,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    $lines = [new DiscountLine('line-1', null, $product->id, ServiceType::Sbc, 10_000)];

    $result = app(DiscountEngine::class)->calculate($lines, $coupon);

    expect($result->totalDiscountHalalah)->toBe(10_000)
        ->and($result->payableTotalHalalah)->toBe(0);

    // and the same coupon at a legitimate 15% still charges the other 85%
    $coupon->forceFill(['value' => 15])->save();
    $fifteen = app(DiscountEngine::class)->calculate($lines, $coupon->fresh());

    expect($fifteen->totalDiscountHalalah)->toBe(1_500)
        ->and($fifteen->payableTotalHalalah)->toBe(8_500);
});

test('a multi-unit line is excluded from cart promotions rather than comped whole', function (): void {
    $category = Category::factory()->create();
    [$productA] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);
    [$productB] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);

    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'اشتر 1 واحصل على 1 مجانا',
        'name_en' => 'Buy one get one free',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_NTH_ITEM,
        'buy_quantity' => 1,
        'get_quantity' => 1,
        'discount_type' => 'percent',
        'value' => 100,
        'is_active' => true,
    ]);

    // Stage 2 counts lines, so a 3-unit line would otherwise be treated as one
    // item and then discounted in full - comping all three units.
    $lines = [
        new DiscountLine('line-1', $category->id, $productA->id, ServiceType::Sbc, 30_000, quantity: 3),
        new DiscountLine('line-2', $category->id, $productB->id, ServiceType::Sbc, 10_000),
    ];

    $result = app(DiscountEngine::class)->calculate($lines);

    expect($result->totalDiscountHalalah)->toBe(0)
        ->and($result->payableTotalHalalah)->toBe(40_000);
});

test('a promotion ending between preview and pay refuses the order instead of charging the new price', function (): void {
    $user = makeCartPromoUser();
    [$product, $variant] = makeCartPromoProduct(priceHalalah: 20_000);

    $promotion = Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض ينتهي',
        'name_en' => 'Expiring offer',
        'scope' => Promotion::SCOPE_PRODUCT,
        'product_id' => $product->id,
        'mechanic' => Promotion::MECHANIC_ITEM,
        'discount_type' => 'percent',
        'value' => 50,
        'is_active' => true,
    ]);

    makeCartWithMultipleLines($user, [['variant' => $variant, 'price' => 20_000]]);

    // The cart showed 10,000 while the promotion was live.
    $previewed = 10_000;

    // The admin switches it off between the page rendering and the customer
    // pressing pay.
    $promotion->forceFill(['is_active' => false])->save();

    expect(fn () => app(PlaceOrder::class)->execute($user, 'ar', 'promo-vanished', $previewed, $previewed))
        ->toThrow(CartRepriced::class)
        ->and(Order::query()->count())->toBe(0);
});

test('checkout still works when no expected total is supplied', function (): void {
    $user = makeCartPromoUser();
    [$product, $variant] = makeCartPromoProduct(priceHalalah: 20_000);

    makeCartWithMultipleLines($user, [['variant' => $variant, 'price' => 20_000]]);

    // An older client that sends no expectation is not broken by the new check;
    // it simply gets the live price.
    $checkout = app(PlaceOrder::class)->execute($user, 'ar', 'no-expectation');

    expect((int) $checkout->order->total_halalah)->toBe(20_000);
});

test('the best cart promotion wins even when a weaker one is evaluated after it', function (): void {
    [$productA] = makeCartPromoProduct(priceHalalah: 10_000);
    [$productB] = makeCartPromoProduct(priceHalalah: 10_000);

    // The STRONGER offer is created first, so it carries the lower id and is seen
    // first. A "keep whichever matched most recently" bug would silently pick the
    // weaker one below and still look plausible - this ordering is the whole point
    // of the test.
    $bundle = Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'باقة قوية',
        'name_en' => 'Strong bundle',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_BUNDLE,
        'bundle_price_halalah' => 12_000,
        'discount_type' => 'fixed',
        'value' => 0,
        'is_active' => true,
    ]);
    PromotionComponent::query()->create([
        'public_id' => (string) Str::ulid(),
        'promotion_id' => $bundle->id,
        'product_id' => $productA->id,
        'quantity' => 1,
    ]);
    PromotionComponent::query()->create([
        'public_id' => (string) Str::ulid(),
        'promotion_id' => $bundle->id,
        'product_id' => $productB->id,
        'quantity' => 1,
    ]);

    // Weaker offer, created second: saves 2,000 against the bundle's 8,000.
    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض أضعف',
        'name_en' => 'Weaker buy one get one',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_NTH_ITEM,
        'buy_quantity' => 1,
        'get_quantity' => 1,
        'discount_type' => 'percent',
        'value' => 20,
        'is_active' => true,
    ]);

    $lines = [
        new DiscountLine('line-1', null, $productA->id, ServiceType::Sbc, 10_000),
        new DiscountLine('line-2', null, $productB->id, ServiceType::Sbc, 10_000),
    ];

    $result = app(DiscountEngine::class)->calculate($lines);

    expect($result->totalDiscountHalalah)->toBe(8_000)
        ->and($result->payableTotalHalalah)->toBe(12_000);
});

test('applies_to_promoted_items = false excludes a line already discounted by an item promotion while true includes it', function (): void {
    $category = Category::factory()->create();
    [$productA, $variantA] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);
    [$productB, $variantB] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);

    // Item promotion: 20% off on Product A only
    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض منتج أ',
        'name_en' => 'Product A Promo',
        'scope' => Promotion::SCOPE_PRODUCT,
        'product_id' => $productA->id,
        'mechanic' => Promotion::MECHANIC_ITEM,
        'discount_type' => 'percent',
        'value' => 20,
        'is_active' => true,
    ]);

    // Cart promotion: Buy 1 get 1 50% off with applies_to_promoted_items = false
    $cartPromo = Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض السلة',
        'name_en' => 'Cart Promo',
        'scope' => Promotion::SCOPE_ALL,
        'mechanic' => Promotion::MECHANIC_NTH_ITEM,
        'buy_quantity' => 1,
        'get_quantity' => 1,
        'discount_type' => 'percent',
        'value' => 50,
        'applies_to_promoted_items' => false,
        'is_active' => true,
    ]);

    $lines = [
        new DiscountLine('line-1', $category->id, $productA->id, ServiceType::Sbc, 10_000),
        new DiscountLine('line-2', $category->id, $productB->id, ServiceType::Sbc, 10_000),
    ];

    $engine = app(DiscountEngine::class);
    $resultFalse = $engine->calculate($lines);

    // Line 1 takes item promotion (2,000 halalah).
    // Because applies_to_promoted_items = false, Line 1 is excluded from cart promotion.
    // Line 2 alone cannot form a buy 1 get 1 pair (needs 2 eligible lines).
    // Total discount = 2,000 (item promo only).
    expect($resultFalse->totalDiscountHalalah)->toBe(2_000)
        ->and($resultFalse->payableTotalHalalah)->toBe(18_000);

    // Now enable applies_to_promoted_items = true
    $cartPromo->update(['applies_to_promoted_items' => true]);

    $engineFresh = new DiscountEngine(new PromotionPricing);
    $resultTrue = $engineFresh->calculate($lines);

    // Line 1 takes item promo: net = 8,000. Line 2 net = 10,000.
    // Cart promo evaluates on [8,000, 10,000]: cheapest line (8,000) gets 50% = 4,000 discount.
    // Total promo discount on Line 1 = 2,000 + 4,000 = 6,000.
    // Promoted subtotal = 4,000 + 10,000 = 14,000. Total discount = 6,000.
    expect($resultTrue->totalDiscountHalalah)->toBe(6_000)
        ->and($resultTrue->payableTotalHalalah)->toBe(14_000);
});

test('existing item promotion behaves exactly as before as a regression guard for default mechanic', function (): void {
    [$product, $variant] = makeCartPromoProduct(priceHalalah: 10_000);

    // Default mechanic is 'item'
    $promo = Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض فردي',
        'name_en' => 'Single item promo',
        'scope' => Promotion::SCOPE_ALL,
        'discount_type' => 'percent',
        'value' => 15,
        'is_active' => true,
    ]);

    // The column default applies on insert, so read it back rather than trusting
    // the in-memory model - and assert the engine treats it as an item promotion,
    // which is what the regression is actually about.
    expect($promo->fresh()->mechanic)->toBe(Promotion::MECHANIC_ITEM);

    $lines = [
        new DiscountLine('line-1', null, $product->id, ServiceType::Sbc, 10_000),
    ];

    $engine = app(DiscountEngine::class);
    $result = $engine->calculate($lines);

    expect($result->totalDiscountHalalah)->toBe(1_500)
        ->and($result->payableTotalHalalah)->toBe(8_500)
        ->and($result->linePromotionDiscounts['line-1'])->toBe(1_500);
});

/* ------------------------------------------------------------------ *
 * Full Integration & PlaceOrder Agreement Test
 * ------------------------------------------------------------------ */

test('cart preview and PlaceOrder agree to the halalah on a cart with an item promotion a cart promotion and a coupon together', function (): void {
    $user = makeCartPromoUser();
    $category = Category::factory()->create();

    [$prod1, $var1] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);
    [$prod2, $var2] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);
    [$prod3, $var3] = makeCartPromoProduct(priceHalalah: 10_000, category: $category);

    // Stage 1: Item promo on Prod 1 (10% off -> 1,000 discount, net = 9,000)
    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض منتج 1',
        'name_en' => 'Product 1 Promo',
        'scope' => Promotion::SCOPE_PRODUCT,
        'product_id' => $prod1->id,
        'mechanic' => Promotion::MECHANIC_ITEM,
        'discount_type' => 'percent',
        'value' => 10,
        'is_active' => true,
    ]);

    // Stage 2: Cart promo: Buy 1 get 1 50% off on Prod 2 only (same_product, applies_to_promoted_items = false)
    // Prod 2 has 2 lines (Line 2 and Line 3) -> 1 line gets 50% off (5,000 discount).
    Promotion::query()->create([
        'public_id' => (string) Str::ulid(),
        'name_ar' => 'عرض السلة للمنتج 2',
        'name_en' => 'Product 2 Buy 1 Get 1 50%',
        'scope' => Promotion::SCOPE_PRODUCT,
        'product_id' => $prod2->id,
        'mechanic' => Promotion::MECHANIC_NTH_ITEM,
        'qualifying_scope' => Promotion::QUALIFYING_SCOPE_SAME_PRODUCT,
        'buy_quantity' => 1,
        'get_quantity' => 1,
        'discount_type' => 'percent',
        'value' => 50,
        'applies_to_promoted_items' => false,
        'is_active' => true,
    ]);

    // Create cart with 3 items: Prod 1, Prod 2, Prod 2
    [$cart, $items] = makeCartWithMultipleLines($user, [
        ['variant' => $var1, 'price' => 10_000],
        ['variant' => $var2, 'price' => 10_000],
        ['variant' => $var2, 'price' => 10_000],
    ]);

    // Stage 3: Coupon: 10% off order
    // Promoted subtotal = 9,000 (Line 1) + 10,000 (Line 2) + 5,000 (Line 3) = 24,000 halalah.
    // Coupon 10% on 24,000 = 2,400 halalah.
    // Total discount = 1,000 (item) + 5,000 (cart promo) + 2,400 (coupon) = 8,400 halalah.
    // Payable total = 21,600 halalah.
    $coupon = Coupon::query()->create([
        'public_id' => (string) Str::ulid(),
        'code' => 'COMBO10',
        'discount_type' => 'percent',
        'value' => 10,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);

    // Apply coupon to cart
    $appliedCoupon = app(ApplyCoupon::class)->apply($cart->refresh(), 'COMBO10', $user);
    expect($appliedCoupon->discountHalalah)->toBe(2_400);

    // Cart calculation preview
    $preview = app(DiscountEngine::class)->calculateForCart($cart->refresh(), $user);
    expect($preview->baseSubtotalHalalah)->toBe(30_000)
        ->and($preview->promotedSubtotalHalalah)->toBe(24_000)
        ->and($preview->totalDiscountHalalah)->toBe(8_400)
        ->and($preview->payableTotalHalalah)->toBe(21_600);

    // Execute PlaceOrder
    $checkout = app(PlaceOrder::class)->execute($user, 'ar', 'combo-promo-checkout-1');
    $order = $checkout->order->fresh(['items', 'discounts']);

    expect($order->subtotal_halalah)->toBe(24_000)
        ->and($order->discount_halalah)->toBe(2_400)
        ->and($order->payment_halalah)->toBe(21_600)
        ->and($order->total_halalah)->toBe(21_600)
        ->and($order->items)->toHaveCount(3);

    // Check item persistence: promotion_discount_halalah matches preview
    $itemDiscounts = $order->items->pluck('promotion_discount_halalah')->all();
    sort($itemDiscounts);
    // Disounts should be 0, 1000, 5000
    expect($itemDiscounts)->toBe([0, 1_000, 5_000])
        ->and(array_sum($itemDiscounts))->toBe(6_000);
});
