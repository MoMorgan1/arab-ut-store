<?php

use App\Actions\Checkout\PlaceOrder;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\User;

/** @return array{user: User, cart: Cart, item: CartItem, variant: ProductVariant} */
function promotedCheckoutCart(array $overrides = []): array
{
    static $phoneSequence = 0;
    $phoneSequence++;
    $user = User::factory()->create([
        'phone' => '+9665'.str_pad((string) $phoneSequence, 8, '0', STR_PAD_LEFT),
        'phone_verified_at' => now(),
    ]);
    $product = Product::factory()->create([
        'service_type' => ServiceType::Objectives,
        'name_ar' => 'خدمة العروض',
        'name_en' => 'Promoted service',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create(array_merge([
        'service_type' => ServiceType::Objectives,
        'platform' => Platform::PlayStation,
        'price_halalah' => 10_000,
        'sale_price_halalah' => null,
        'price_version' => 3,
        'is_active' => true,
    ], $overrides));
    $cart = Cart::create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    $item = $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => 10_000,
        'total_halalah' => 10_000,
        'configuration' => [
            'service_type' => ServiceType::Objectives->value,
            'platform' => Platform::PlayStation->value,
            'market' => Platform::PlayStation->market()->value,
            'quoted_at' => now()->utc()->toIso8601String(),
            'price_version' => 3,
        ],
    ]);

    return compact('user', 'cart', 'item', 'variant');
}

function activeCartPromotion(array $overrides = []): Promotion
{
    return Promotion::query()->create(array_merge([
        'name_ar' => 'عرض تلقائي',
        'name_en' => 'Automatic deal',
        'badge_ar' => 'خصم 20%',
        'badge_en' => '20% off',
        'scope' => Promotion::SCOPE_ALL,
        'discount_type' => 'percent',
        'value' => 20,
        'is_active' => true,
    ], $overrides));
}

test('checkout snapshots the best active promotion onto the order item and charges the promoted subtotal', function (): void {
    ['user' => $user] = promotedCheckoutCart();
    $promotion = activeCartPromotion();

    $result = app(PlaceOrder::class)->execute($user, 'ar', 'promotion-checkout-1');
    $order = $result->order->fresh(['items']);

    expect($order->subtotal_halalah)->toBe(8_000)
        ->and($order->discount_halalah)->toBe(0)
        ->and($order->payment_halalah)->toBe(8_000)
        ->and($order->total_halalah)->toBe(8_000)
        ->and($order->items)->toHaveCount(1);

    $item = $order->items->first();

    expect($item?->subtotal_halalah)->toBe(10_000)
        ->and($item?->promotion_id)->toBe($promotion->id)
        ->and($item?->promotion_discount_halalah)->toBe(2_000)
        ->and($item?->discount_halalah)->toBe(2_000)
        ->and($item?->total_halalah)->toBe(8_000);
});

test('the coupon applies on top of the promoted subtotal', function (): void {
    ['user' => $user, 'cart' => $cart] = promotedCheckoutCart();
    activeCartPromotion(['value' => 20]);
    $coupon = Coupon::query()->create([
        'code' => 'STACK10',
        'discount_type' => 'percent',
        'value' => 10,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ]);
    $cart->forceFill(['coupon_id' => $coupon->id])->save();

    $result = app(PlaceOrder::class)->execute($user, 'en', 'promotion-checkout-2');
    $order = $result->order->fresh(['items', 'discounts']);

    expect($order->subtotal_halalah)->toBe(8_000)
        ->and($order->discount_halalah)->toBe(800)
        ->and($order->payment_halalah)->toBe(7_200)
        ->and($order->total_halalah)->toBe(7_200)
        ->and($order->items->first()?->promotion_discount_halalah)->toBe(2_000)
        ->and($order->items->first()?->total_halalah)->toBe(8_000)
        ->and($order->discounts()->where('coupon_id', $coupon->id)->count())->toBe(1);
});

test('an inactive or expired promotion never discounts the order', function (): void {
    ['user' => $user] = promotedCheckoutCart();
    activeCartPromotion(['is_active' => false]);

    $result = app(PlaceOrder::class)->execute($user, 'ar', 'promotion-checkout-3');
    $order = $result->order->fresh(['items']);

    expect($order->subtotal_halalah)->toBe(10_000)
        ->and($order->payment_halalah)->toBe(10_000)
        ->and($order->items->first()?->promotion_id)->toBeNull()
        ->and($order->items->first()?->promotion_discount_halalah)->toBe(0)
        ->and($order->items->first()?->total_halalah)->toBe(10_000);
});

test('the best promotion wins when several scopes overlap at checkout', function (): void {
    ['user' => $user] = promotedCheckoutCart();
    activeCartPromotion(['value' => 20]);
    Promotion::query()->create([
        'name_ar' => 'خصم ثابت أكبر',
        'name_en' => 'Bigger fixed deal',
        'badge_ar' => 'خصم 30 ر.س',
        'badge_en' => '30 SAR off',
        'scope' => Promotion::SCOPE_SERVICE,
        'service_type' => ServiceType::Objectives->value,
        'discount_type' => 'fixed',
        'value' => 3_000,
        'is_active' => true,
    ]);

    $result = app(PlaceOrder::class)->execute($user, 'ar', 'promotion-checkout-4');
    $order = $result->order->fresh(['items']);

    expect($order->subtotal_halalah)->toBe(7_000)
        ->and($order->items->first()?->promotion_discount_halalah)->toBe(3_000);
});
