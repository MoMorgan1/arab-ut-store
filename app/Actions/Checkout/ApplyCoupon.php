<?php

namespace App\Actions\Checkout;

use App\Checkout\AppliedCoupon;
use App\Enums\CouponRejection;
use App\Exceptions\Checkout\CouponRejected;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class ApplyCoupon
{
    private const PERCENT = 'percent';

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
                ->lockForUpdate()
                ->firstOrFail();

            $coupon = Coupon::query()
                ->where('code', mb_strtoupper(trim($code)))
                ->lockForUpdate()
                ->first();

            if (! $coupon instanceof Coupon) {
                throw new CouponRejected(CouponRejection::Invalid);
            }

            $applied = $this->evaluate($coupon, $this->subtotal($lockedCart), $user);

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
        if (! $coupon->is_active || ! in_array($coupon->discount_type, [self::PERCENT, 'fixed'], true)) {
            throw new CouponRejected(CouponRejection::Invalid);
        }

        $now = now();

        if (($coupon->starts_at !== null && $now->lt($coupon->starts_at))
            || ($coupon->ends_at !== null && $now->gt($coupon->ends_at))) {
            throw new CouponRejected(CouponRejection::Expired);
        }

        if ($coupon->usage_limit !== null
            && $coupon->redemptions()->count() >= $coupon->usage_limit) {
            throw new CouponRejected(CouponRejection::Limit);
        }

        if ($coupon->per_user_limit !== null
            && $user !== null
            && $coupon->redemptions()->where('user_id', $user->id)->count() >= $coupon->per_user_limit) {
            throw new CouponRejected(CouponRejection::Limit);
        }

        if ($subtotalHalalah < $coupon->minimum_order_halalah) {
            throw new CouponRejected(CouponRejection::Minimum, (int) $coupon->minimum_order_halalah);
        }

        return new AppliedCoupon(
            couponId: (int) $coupon->id,
            code: (string) $coupon->code,
            discountType: (string) $coupon->discount_type,
            discountHalalah: $this->discountFor($coupon, $subtotalHalalah),
        );
    }

    private function discountFor(Coupon $coupon, int $subtotalHalalah): int
    {
        if ($coupon->discount_type === self::PERCENT) {
            $discount = intdiv($subtotalHalalah * (int) $coupon->value, 100);

            if ($coupon->maximum_discount_halalah !== null) {
                $discount = min($discount, (int) $coupon->maximum_discount_halalah);
            }

            return min($discount, $subtotalHalalah);
        }

        return min((int) $coupon->value, $subtotalHalalah);
    }

    private function subtotal(Cart $cart): int
    {
        return (int) $cart->items()->sum('total_halalah');
    }
}
