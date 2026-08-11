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
        public int $priceVersion,
        public Platform $platform,
        public ?DeliveryMode $delivery,
        public int $quantity,
        public Money $total,
        public CarbonImmutable $pricedAt,
    ) {}

    /**
     * @param  array{amountMinor: int, currency: string}  $displayTotal
     * @return array<string, mixed>
     */
    public function toArray(array $displayTotal): array
    {
        return [
            'productId' => $this->productId,
            'variantId' => $this->variantId,
            'priceVersion' => $this->priceVersion,
            'platform' => $this->platform->value,
            'market' => $this->platform->market()->value,
            'delivery' => $this->delivery?->value,
            'quantity' => $this->quantity,
            'total' => [
                'amountHalalah' => $this->total->halalah(),
                'currency' => $this->total->currency(),
            ],
            'displayTotal' => $displayTotal,
            'pricedAt' => $this->pricedAt->utc()->toIso8601String(),
        ];
    }
}
