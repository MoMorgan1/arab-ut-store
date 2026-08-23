<?php

namespace App\Checkout;

final readonly class AppliedCoupon
{
    /**
     * @param  array<int|string, int>  $allocations
     */
    public function __construct(
        public int $couponId,
        public string $code,
        public string $discountType,
        public int $discountHalalah,
        public array $allocations = [],
    ) {}
}
