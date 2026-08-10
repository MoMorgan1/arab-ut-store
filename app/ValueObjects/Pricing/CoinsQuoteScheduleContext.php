<?php

namespace App\ValueObjects\Pricing;

use App\Enums\DeliveryMode;
use App\Enums\Platform;

final readonly class CoinsQuoteScheduleContext
{
    public function __construct(
        public string $productId,
        public string $variantId,
        public int $priceVersion,
        public Platform $platform,
        public ?DeliveryMode $delivery,
        public CoinsPricingRule $rule,
        public ?CoinsPricingRule $normalRule,
        public PreparedDisplayMoneyConverter $displayConverter,
    ) {}
}
