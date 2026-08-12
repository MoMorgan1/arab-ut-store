<?php

namespace App\Actions\Pricing;

use App\Enums\DeliveryMode;
use App\Enums\Platform;
use DomainException;

final readonly class ReadSbcCoinsPricingBases
{
    private const QUANTITY = 1_000_000;

    public function __construct(private QuoteCoins $quoteCoins) {}

    /**
     * @return array{
     *   schemaVersion: 1,
     *   pricingVersion: int,
     *   pricedAt: string,
     *   quotes: array{
     *     playstation_fast: array{platform: string, delivery: string, quantity: int, totalHalalah: int},
     *     pc: array{platform: string, delivery: null, quantity: int, totalHalalah: int}
     *   }
     * }
     */
    public function execute(): array
    {
        $pricedAt = now()->utc();
        $playstation = $this->quoteCoins->execute(
            Platform::PlayStation,
            DeliveryMode::Fast,
            self::QUANTITY,
        );
        $pc = $this->quoteCoins->execute(Platform::Pc, null, self::QUANTITY);

        if ($playstation->priceVersion <= 0
            || $playstation->priceVersion !== $pc->priceVersion) {
            throw new DomainException('The active Coins variant versions are unavailable or inconsistent.');
        }

        return [
            'schemaVersion' => 1,
            'pricingVersion' => $playstation->priceVersion,
            'pricedAt' => $pricedAt->toIso8601String(),
            'quotes' => [
                'playstation_fast' => [
                    'platform' => Platform::PlayStation->value,
                    'delivery' => DeliveryMode::Fast->value,
                    'quantity' => self::QUANTITY,
                    'totalHalalah' => $playstation->total->halalah(),
                ],
                'pc' => [
                    'platform' => Platform::Pc->value,
                    'delivery' => null,
                    'quantity' => self::QUANTITY,
                    'totalHalalah' => $pc->total->halalah(),
                ],
            ],
        ];
    }
}
