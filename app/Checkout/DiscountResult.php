<?php

namespace App\Checkout;

use App\Marketing\PromotionPrice;
use App\Models\Promotion;

final readonly class DiscountResult
{
    /**
     * @param  array<int|string, PromotionPrice|null>  $linePromotions
     * @param  array<int|string, int>  $linePromotionDiscounts
     * @param  array<int|string, int>  $lineNetHalalah
     * @param  array<int|string, int>  $lineCouponDiscounts
     */
    public function __construct(
        public int $baseSubtotalHalalah,
        public int $promotedSubtotalHalalah,
        public int $totalDiscountHalalah,
        public int $payableTotalHalalah,
        public array $linePromotions,
        public array $linePromotionDiscounts,
        public array $lineNetHalalah,
        public array $lineCouponDiscounts,
        public ?AppliedCoupon $appliedCoupon,
        public ?Promotion $appliedCartPromotion = null,
        public int $cartPromotionDiscountHalalah = 0,
    ) {}

    public function linePromotion(int|string $lineId): ?PromotionPrice
    {
        return $this->linePromotions[$lineId] ?? null;
    }
}
