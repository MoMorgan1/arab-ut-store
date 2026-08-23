<?php

namespace App\Actions\Checkout;

use App\Checkout\AppliedCoupon;
use App\Checkout\DiscountEngine;
use App\Enums\CouponRejection;
use App\Exceptions\Checkout\CouponRejected;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class ApplyCoupon
{
    public function __construct(
        private DiscountEngine $discountEngine,
    ) {}

    /**
     * Validate a coupon against the cart subtotal and attach it to the cart.
     *
     * @throws CouponRejected
     */
    public function apply(Cart $cart, string $code, ?User $user): AppliedCoupon
    {
        return DB::transaction(function () use ($cart, $code, $user): AppliedCoupon {
            /** @var Cart $lockedCart */
            $lockedCart = Cart::query()
                ->whereKey($cart->getKey())
                ->with(['items.productVariant.product.category'])
                ->lockForUpdate()
                ->firstOrFail();

            $coupon = Coupon::query()
                ->where('code', mb_strtoupper(trim($code)))
                ->with('targets')
                ->lockForUpdate()
                ->first();

            if (! $coupon instanceof Coupon) {
                throw new CouponRejected(CouponRejection::Invalid);
            }

            $applied = $this->discountEngine->evaluateCartCoupon($lockedCart, $coupon, $user);

            $lockedCart->forceFill(['coupon_id' => $coupon->id])->save();

            return $applied;
        }, attempts: 3);
    }

    public function remove(Cart $cart): void
    {
        Cart::query()
            ->whereKey($cart->getKey())
            ->whereNotNull('coupon_id')
            ->update(['coupon_id' => null]);
    }

    /**
     * Evaluate an already-locked coupon against a known subtotal.
     *
     * @throws CouponRejected
     */
    public function evaluate(Coupon $coupon, int $subtotalHalalah, ?User $user): AppliedCoupon
    {
        return $this->discountEngine->evaluateSimpleCoupon($coupon, $subtotalHalalah, $user);
    }
}
