<?php

namespace App\Checkout;

final readonly class AppliedCoupon
{
    public function __construct(
        public readonly int $couponId,
        public readonly string $code,
        public readonly string $discountType,
        public readonly int $discountHalalah,
    ) {}
}
