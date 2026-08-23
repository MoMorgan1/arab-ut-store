<?php

namespace App\Marketing;

use App\Models\Promotion;

/**
 * Immutable result of resolving the best active promotion for one priced item.
 */
final readonly class PromotionPrice
{
    public function __construct(
        public Promotion $promotion,
        public int $baseHalalah,
        public int $discountHalalah,
        public int $discountedHalalah,
    ) {}
}
