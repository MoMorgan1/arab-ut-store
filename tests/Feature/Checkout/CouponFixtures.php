<?php

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
use App\Models\CartItemSecret;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function couponCartAttributes(array $overrides = []): array
{
    return array_merge([
        'public_id' => (string) Str::ulid(),
        'code' => 'COUPONX1',
        'discount_type' => 'percent',
        'value' => 10,
        'minimum_order_halalah' => 0,
        'is_active' => true,
    ], $overrides);
}

function couponShopperUser(): User
{
    static $sequence = 0;
    $sequence++;

    return User::factory()->create([
        'phone' => '+9665'.str_pad((string) (7_000_000 + $sequence), 8, '0', STR_PAD_LEFT),
        'phone_verified_at' => now(),
    ]);
}

/**
 * A guest-owned cart plus the raw session token that resolves to it.
 *
 * @return array{0: string, 1: Cart}
 */
function couponGuestCart(int $subtotal = 1250): array
{
    $rawToken = str_repeat('ab', 32);
    $sessionKey = hash_hmac('sha256', $rawToken, (string) config('app.key'));

    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'name_ar' => 'تحدي سلة الضيف',
        'name_en' => 'Guest cart challenge',
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'price_halalah' => $subtotal,
        'sale_price_halalah' => null,
        'price_version' => 4,
        'is_active' => true,
    ]);
    $cart = Cart::query()->create([
        'user_id' => null,
        'session_key' => $sessionKey,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    attachCouponCartItem($cart, $variant, $subtotal);

    return [$rawToken, $cart->refresh()];
}

function attachCouponShopperCart(User $user, int $subtotal): Cart
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'name_ar' => "تحدي كوبون {$user->id}",
        'name_en' => "Coupon challenge {$user->id}",
        'is_visible' => true,
        'archived_at' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'price_halalah' => $subtotal,
        'sale_price_halalah' => null,
        'price_version' => 4,
        'is_active' => true,
    ]);
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    attachCouponCartItem($cart, $variant, $subtotal);

    return $cart->refresh();
}

function attachCouponCartItem(Cart $cart, ProductVariant $variant, int $subtotal): void
{
    $item = $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => $subtotal,
        'total_halalah' => $subtotal,
        'configuration' => [
            'service_type' => 'sbc',
            'platform' => 'playstation',
            'market' => 'console',
            'completion_count' => 1,
            'quoted_at' => now()->utc()->toIso8601String(),
            'price_version' => 4,
        ],
    ]);
    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => ['has_password' => true, 'backup_code_count' => 3],
        'retained_until' => null,
        'deleted_at' => null,
    ]);
    $secret->encrypted_payload = [
        'ea_email' => "coupon-shopper-{$cart->id}@example.test",
        'ea_password' => 'Opaque coupon password',
        'backup_codes' => ['64000001', '64000002', '64000003'],
    ];
    $secret->save();
}
