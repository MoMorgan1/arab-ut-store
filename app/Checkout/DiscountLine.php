<?php

namespace App\Checkout;

use App\Enums\ServiceType;

final readonly class DiscountLine
{
    public function __construct(
        public int|string $id,
        public ?int $categoryId,
        public ?int $productId,
        public ServiceType $serviceType,
        public int $basePriceHalalah,
        public int $quantity = 1,
    ) {}
}
