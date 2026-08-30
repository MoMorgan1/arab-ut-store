<?php

use App\Actions\Cart\ResolveCartOwner;
use App\Actions\Checkout\ApplyCoupon;
use App\Actions\Checkout\PlaceOrder;
use App\Enums\OrderStatus;
use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

afterEach(function (): void {
    Carbon::setTestNow();
});

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00', 'UTC'));
});

test('a guest cart can apply and remove a coupon', function (): void {
    [$rawToken, $cart] = couponGuestCart();
    Coupon::query()->create(couponCartAttributes(['code' => 'GUEST10']));

    $this->withSession([ResolveCartOwner::SESSION_KEY => $rawToken])
        ->postJson('/cart/coupon', ['code' => ' guest10 '])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['data' => [
            'code' => 'GUEST10',
            'discountType' => 'percent',
            'discountHalalah' => 125,
        ]]);

    expect($cart->fresh()->coupon_id)->not->toBeNull();

    $this->withSession([ResolveCartOwner::SESSION_KEY => $rawToken])
        ->deleteJson('/cart/coupon')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['data' => ['removed' => true]]);

    expect($cart->fresh()->coupon_id)->toBeNull();
});

test('the localized cart page exposes the applied coupon and coupon endpoints', function (): void {
    [$user, $cart] = couponUserCart(1250);
    Coupon::query()->create(couponCartAttributes([
        'code' => 'PAGE10',
        'value' => 100,
        'discount_type' => 'fixed',
    ]));

    app(ApplyCoupon::class)->apply($cart->refresh(), 'PAGE10', $user);

    $this->actingAs($user)
        ->get('/en/cart')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('cart.coupon.code', 'PAGE10')
            ->where('cart.coupon.discountType', 'fixed')
            ->where('cart.coupon.discountHalalah', 100)
            ->where('cartPage.checkout.couponApplyUrl', '/en/cart/coupon')
            ->where('cartPage.checkout.couponRemoveUrl', '/en/cart/coupon'));
});

test('unknown inactive and disabled coupons are rejected as invalid', function (Closure $seed): void {
    [$rawToken] = couponGuestCart();
    $code = $seed();

    $this->withSession([ResolveCartOwner::SESSION_KEY => $rawToken])
        ->postJson('/cart/coupon', ['code' => $code])
        ->assertStatus(422)
        ->assertJson(['error' => ['code' => 'coupon_invalid']])
        ->assertJsonPath('error.message', 'رمز الكوبون غير صحيح.');
})->with([
    'unknown code' => [fn (): string => 'NOSUCHCODE'],
    'inactive coupon' => [function (): string {
        Coupon::query()->create(couponCartAttributes(['code' => 'DISABLED', 'is_active' => false]));

        return 'DISABLED';
    }],
]);

test('coupons outside their window are rejected as expired', function (array $overrides): void {
    [$rawToken] = couponGuestCart();
    Coupon::query()->create(couponCartAttributes(['code' => 'LATECODE', ...$overrides]));

    $this->withSession([ResolveCartOwner::SESSION_KEY => $rawToken])
        ->postJson('/cart/coupon', ['code' => 'LATECODE'])
        ->assertStatus(422)
        ->assertJson(['error' => ['code' => 'coupon_expired']]);
})->with([
    'not started yet' => [['starts_at' => '2026-08-24 00:00:00']],
    'already ended' => [['ends_at' => '2026-08-22 00:00:00']],
]);

test('exhausted total usage limits are rejected', function (): void {
    [$rawToken, $cart] = couponGuestCart();
    $coupon = Coupon::query()->create(couponCartAttributes(['code' => 'FULLUP', 'usage_limit' => 1]));
    $other = User::factory()->create();
    $paidOrder = Order::query()->create(orderAttributesForCouponTest($other));
    CouponRedemption::query()->create([
        'public_id' => (string) Str::ulid(),
        'coupon_id' => $coupon->id,
        'user_id' => $other->id,
        'order_id' => $paidOrder->id,
    ]);

    $this->withSession([ResolveCartOwner::SESSION_KEY => $rawToken])
        ->postJson('/cart/coupon', ['code' => 'FULLUP'])
        ->assertStatus(422)
        ->assertJson(['error' => ['code' => 'coupon_limit']]);

    expect($cart->fresh()->coupon_id)->toBeNull();
});

test('per-user limits bind only the identified customer', function (): void {
    [$user, $cart] = couponUserCart(1250);
    $coupon = Coupon::query()->create(couponCartAttributes(['code' => 'TWICEMAX', 'per_user_limit' => 1]));
    $redeemed = Order::query()->create(orderAttributesForCouponTest($user));
    CouponRedemption::query()->create([
        'public_id' => (string) Str::ulid(),
        'coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'order_id' => $redeemed->id,
    ]);

    $this->actingAs($user)
        ->postJson('/cart/coupon', ['code' => 'TWICEMAX'])
        ->assertStatus(422)
        ->assertJson(['error' => ['code' => 'coupon_limit']])
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($cart->fresh()->coupon_id)->toBeNull();
});

test('orders below the coupon minimum are rejected with an amount message', function (): void {
    [$rawToken] = couponGuestCart();
    Coupon::query()->create(couponCartAttributes(['code' => 'BIGSPEND', 'minimum_order_halalah' => 5000]));

    $this->withSession([ResolveCartOwner::SESSION_KEY => $rawToken])
        ->postJson('/cart/coupon', ['code' => 'BIGSPEND'])
        ->assertStatus(422)
        ->assertJson(['error' => ['code' => 'coupon_minimum']])
        ->assertJsonPath('error.message', 'يجب أن يكون إجمالي طلبك على الأقل SAR 50.00 لاستخدام هذا الكوبون.');
});

test('percent discounts floor and respect the maximum cap', function (): void {
    $capped = Coupon::query()->create(couponCartAttributes([
        'code' => 'CAPPED30',
        'value' => 30,
        'maximum_discount_halalah' => 200,
    ]));

    $floored = Coupon::query()->create(couponCartAttributes(['code' => 'FLOOR15', 'value' => 15]));

    expect(app(ApplyCoupon::class)->evaluate($capped, 1250, null)->discountHalalah)->toBe(200)
        ->and(app(ApplyCoupon::class)->evaluate($floored, 1250, null)->discountHalalah)->toBe(187);
});

test('fixed discounts never exceed the subtotal', function (): void {
    $coupon = Coupon::query()->create(couponCartAttributes([
        'code' => 'FLAT5',
        'discount_type' => 'fixed',
        'value' => 500,
    ]));

    expect(app(ApplyCoupon::class)->evaluate($coupon, 300, null)->discountHalalah)->toBe(300)
        ->and(app(ApplyCoupon::class)->evaluate($coupon, 5000, null)->discountHalalah)->toBe(500);
});

test('placing an order redeems the coupon once and records totals and ledgers', function (): void {
    $user = couponShopperUser('place-order');
    $cart = attachCouponShopperCart($user, 2500);
    $coupon = Coupon::query()->create(couponCartAttributes([
        'code' => 'CHECKOUT25',
        'value' => 25,
        'usage_limit' => 10,
        'per_user_limit' => 2,
    ]));
    $cart->update(['coupon_id' => $coupon->id]);

    $result = app(PlaceOrder::class)->execute($user, 'ar', 'coupon-place-once');
    $order = $result->order->fresh(['discounts']);

    expect($order->subtotal_halalah)->toBe(2500)
        ->and($order->discount_halalah)->toBe(625)
        ->and($order->total_halalah)->toBe(1875)
        ->and($order->payment_halalah)->toBe(1875)
        ->and($result->payment->amount_halalah)->toBe(1875)
        ->and($order->discounts)->toHaveCount(1);

    $discount = $order->discounts->sole();

    expect($discount->coupon_id)->toBe((int) $coupon->id)
        ->and($discount->type)->toBe('percent')
        ->and($discount->amount_halalah)->toBe(625)
        ->and($discount->metadata['code'])->toBe('CHECKOUT25');

    $redemption = CouponRedemption::query()->sole();

    expect($redemption->coupon_id)->toBe((int) $coupon->id)
        ->and($redemption->user_id)->toBe($user->id)
        ->and($redemption->order_id)->toBe($order->id);

    $replayed = app(PlaceOrder::class)->execute($user, 'ar', 'coupon-place-once');

    expect($replayed->replayed)->toBeTrue()
        ->and($replayed->order->is($result->order))->toBeTrue()
        ->and(Order::query()->count())->toBe(1)
        ->and(CouponRedemption::query()->count())->toBe(1)
        ->and(OrderDiscount::query()->count())->toBe(1);
});

test('a coupon invalidated after apply fails checkout and clears from the cart', function (): void {
    [$user, $cart] = couponSbcCartForCheckout();
    $coupon = Coupon::query()->create(couponCartAttributes(['code' => 'VANISH10']));
    $cart->update(['coupon_id' => $coupon->id]);
    $coupon->update(['is_active' => false]);

    expect(fn () => app(PlaceOrder::class)->execute($user, 'ar', 'coupon-vanished'))
        ->toThrow(CheckoutUnavailable::class, 'The applied coupon is no longer valid.');

    expect($cart->fresh()->coupon_id)->toBeNull()
        ->and(Order::query()->count())->toBe(0);
});

test('a deleted coupon row fails checkout and clears from the cart', function (): void {
    [$user, $cart] = couponSbcCartForCheckout();
    $coupon = Coupon::query()->create(couponCartAttributes(['code' => 'GONECODE']));
    $cart->update(['coupon_id' => $coupon->id]);
    $coupon->delete();

    expect(fn () => app(PlaceOrder::class)->execute($user, 'ar', 'coupon-deleted'))
        ->toThrow(CheckoutUnavailable::class, 'The applied coupon is unavailable.')
        ->and($cart->fresh()->coupon_id)->toBeNull();
});

test('a discount may not push the payable total below the Paylink minimum', function (): void {
    [$user, $cart] = couponSbcCartForCheckout(subtotal: 600);
    $coupon = Coupon::query()->create(couponCartAttributes([
        'code' => 'TOOBIG',
        'discount_type' => 'fixed',
        'value' => 400,
    ]));
    $cart->update(['coupon_id' => $coupon->id]);

    expect(fn () => app(PlaceOrder::class)->execute($user, 'ar', 'coupon-too-big'))
        ->toThrow(CheckoutUnavailable::class, (string) trans(
            'store.checkout.paylink_minimum_gap',
            ['gap' => '3.00'],
            locale: 'ar',
        ))
        ->and(Order::query()->count())->toBe(0)
        ->and($cart->fresh()->status)->toBe('active');
});

/* ------------------------------------------------------------------ *
 * Fixtures
 * ------------------------------------------------------------------ */

/** @return array<string, mixed> */
function orderAttributesForCouponTest(User $user): array
{
    return [
        'public_id' => (string) Str::ulid(),
        'user_id' => $user->id,
        'order_number' => 'AR-'.strtoupper((string) Str::ulid()),
        'status' => OrderStatus::Completed,
        'locale' => 'ar',
        'currency' => 'SAR',
        'subtotal_halalah' => 1250,
        'discount_halalah' => 0,
        'wallet_halalah' => 0,
        'payment_halalah' => 1250,
        'total_halalah' => 1250,
        'placed_at' => now(),
    ];
}

/**
 * A verified shopper with one valid SBC cart item.
 *
 * @return array{0: User, 1: Cart}
 */
function couponUserCart(int $subtotal = 1250): array
{
    $user = couponShopperUser();
    $cart = attachCouponShopperCart($user, $subtotal);

    return [$user, $cart];
}

/** @return array{0: User, 1: Cart} */
function couponSbcCartForCheckout(int $subtotal = 1250): array
{
    return couponUserCart($subtotal);
}
