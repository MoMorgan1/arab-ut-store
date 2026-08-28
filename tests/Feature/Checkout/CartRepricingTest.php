<?php

use App\Actions\Cart\RepriceCart;
use App\Actions\Cart\ResolveCartOwner;
use App\Actions\Checkout\PlaceOrder;
use App\Enums\CartItemUnavailableReason;
use App\Exceptions\Checkout\CartRepriced;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\ProductVariant;
use Inertia\Testing\AssertableInertia as Assert;

/** The variant behind a fixture cart's only line. */
function cartRepricingVariant(Cart $cart): ProductVariant
{
    return ProductVariant::query()->whereKey($cart->fresh(['items'])->items->sole()->product_variant_id)->sole();
}

test('a coupon that stops qualifying is detached, reported, and does not loop', function (): void {
    $user = couponShopperUser();
    $cart = attachCouponShopperCart($user, 6000);
    $coupon = Coupon::query()->create(couponCartAttributes([
        'code' => 'MINSPEND',
        'minimum_order_halalah' => 5000,
    ]));
    $cart->forceFill(['coupon_id' => $coupon->id])->save();

    // The cart showed 5,400: 6,000 less the 10% coupon.
    cartRepricingVariant($cart)->forceFill(['price_halalah' => 4000, 'price_version' => 5])->save();

    try {
        app(PlaceOrder::class)->execute($user, 'ar', 'coupon-minimum-reprice', 5400, 5400);
        $this->fail('Expected the coupon falling off to stop the charge.');
    } catch (CartRepriced $repriced) {
        expect($repriced->couponRemoved)->toBeTrue()
            ->and($repriced->orderTotalHalalah)->toBe(4000)
            ->and($repriced->previousOrderTotalHalalah)->toBe(5400);
    }

    // Detached on the way out. Without this the confirming retry is rejected
    // for the same reason, forever.
    expect((int) Order::query()->count())->toBe(0)
        ->and($cart->fresh()->coupon_id)->toBeNull();

    $checkout = app(PlaceOrder::class)->execute($user, 'ar', 'coupon-minimum-confirmed', 4000, 4000);

    expect($checkout->order->total_halalah)->toBe(4000)
        ->and($checkout->order->discount_halalah)->toBe(0);
});

test('a coupon rejected for a reason other than the minimum is not reported as a repricing', function (): void {
    $user = couponShopperUser();
    $cart = attachCouponShopperCart($user, 6000);
    $coupon = Coupon::query()->create(couponCartAttributes([
        'code' => 'SWITCHOFF',
        'is_active' => false,
    ]));
    $cart->forceFill(['coupon_id' => $coupon->id])->save();

    expect(fn () => app(PlaceOrder::class)->execute($user, 'ar', 'coupon-expired-reprice', 5400, 5400))
        ->toThrow(CheckoutUnavailable::class);

    expect(Order::query()->count())->toBe(0);
});

test('the cart page shows the live price and never writes the repriced figure back', function (): void {
    $user = couponShopperUser();
    $cart = attachCouponShopperCart($user, 6000);
    cartRepricingVariant($cart)->forceFill(['price_halalah' => 7000, 'price_version' => 9])->save();

    $this->actingAs($user)->get('/cart')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('cart.items.0.totalHalalah', 7000)
            ->where('cart.items.0.previousTotalHalalah', 6000)
            ->where('cart.items.0.priceChanged', true)
            ->where('cart.canCheckout', true));

    // Rendering is a GET. The stored row keeps the price the item was added at.
    expect((int) CartItem::query()->sole()->total_halalah)->toBe(6000)
        ->and((int) CartItem::query()->sole()->unit_price_halalah)->toBe(6000)
        ->and($cart->fresh()->status)->toBe('active');
});

test('an unavailable item is marked in place and blocks checkout until it is removed', function (): void {
    $user = couponShopperUser();
    $cart = attachCouponShopperCart($user, 6000);
    cartRepricingVariant($cart)->forceFill(['is_active' => false])->save();

    $this->actingAs($user)->get('/cart')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('cart.items.0.unavailableReason', CartItemUnavailableReason::VariantInactive->value)
            ->where('cart.items.0.promotion', null)
            ->where('cart.canCheckout', false));

    // Nothing was deleted on the customer's behalf.
    expect($cart->fresh(['items'])->items)->toHaveCount(1);

    $this->actingAs($user)->delete('/cart/items/'.$cart->fresh(['items'])->items->sole()->public_id)
        ->assertOk();

    $this->actingAs($user)->get('/cart')
        ->assertInertia(fn (Assert $page) => $page->where('cart.canCheckout', false));
});

test('a guest cart is repriced on render too', function (): void {
    [$rawToken, $cart] = couponGuestCart(6000);
    cartRepricingVariant($cart)->forceFill(['price_halalah' => 6500, 'price_version' => 3])->save();

    $this->withSession([ResolveCartOwner::SESSION_KEY => $rawToken])
        ->get('/cart')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('cart.items.0.totalHalalah', 6500)
            ->where('cart.items.0.priceChanged', true));
});

test('the repricer separates a replaced variant from a pricing run in flight', function (): void {
    // Both legs share one comparison in the original checkout code. A version
    // bump on the same variant is a run committing right now and clears on its
    // own; a different variant id is permanent and must be repriced, not
    // retried.
    $user = couponShopperUser();
    $cart = attachCouponShopperCart($user, 6000);
    $variant = cartRepricingVariant($cart);

    $repricing = app(RepriceCart::class)->execute($cart->fresh(['items']));
    $price = $repricing->for($cart->fresh(['items'])->items->sole());

    expect($price->isPriced())->toBeTrue()
        ->and($price->pricingRunInProgress)->toBeFalse()
        ->and($price->variant?->is($variant))->toBeTrue();
});

test('checkout refuses a cart whose stored configuration cannot be priced', function (): void {
    $user = couponShopperUser();
    $cart = attachCouponShopperCart($user, 6000);
    $item = $cart->fresh(['items'])->items->sole();
    $item->forceFill(['configuration' => [...$item->configuration, 'completion_count' => 0]])->save();

    $repricing = app(RepriceCart::class)->execute($cart->fresh(['items']));

    expect($repricing->for($cart->fresh(['items'])->items->sole())->unavailableReason)
        ->toBe(CartItemUnavailableReason::ConfigurationInvalid);
});
