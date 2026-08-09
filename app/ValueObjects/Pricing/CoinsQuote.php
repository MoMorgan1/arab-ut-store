<?php

namespace App\ValueObjects\Pricing;

use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Support\Money;
use Carbon\CarbonImmutable;

final readonly class CoinsQuote
{
    public function __construct(
        public string $productId,
        public string $variantId,
        public Platform $platform,
        public ?DeliveryMode $delivery,
        public int $quantity,
        public Money $total,
        public CarbonImmutable $pricedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'productId' => $this->productId,
            'variantId' => $this->variantId,
            'platform' => $this->platform->value,
            'market' => $this->platform->market()->value,
            'delivery' => $this->delivery?->value,
            'quantity' => $this->quantity,
            'total' => [
                'amountHalalah' => $this->total->halalah(),
                'currency' => $this->total->currency(),
            ],
            'pricedAt' => $this->pricedAt->utc()->toIso8601String(),
        ];
    }
}
