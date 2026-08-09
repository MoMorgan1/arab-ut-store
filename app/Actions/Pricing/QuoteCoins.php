<?php

namespace App\Actions\Pricing;

use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Services\Catalog\CoinsCatalogReader;
use App\Services\Pricing\CoinsPriceCalculator;
use App\ValueObjects\Pricing\CoinsQuote;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class QuoteCoins
{
    public function __construct(
        private CoinsCatalogReader $catalog,
        private CoinsPriceCalculator $calculator,
    ) {}

    public function execute(Platform $platform, ?DeliveryMode $delivery, int $quantity): CoinsQuote
    {
        if (($platform === Platform::Pc && $delivery !== null)
            || ($platform !== Platform::Pc && $delivery === null)) {
            throw new InvalidArgumentException('The delivery mode does not match the selected platform.');
        }

        $product = $this->catalog->product();
        $variant = $this->catalog->variant($product, $platform);
        $group = match (true) {
            $platform === Platform::Pc => 'pc',
            $delivery === DeliveryMode::Normal => 'console_normal',
            default => 'console_fast',
        };
        $rules = $this->catalog->pricingRules([$group]);
        $normalRule = null;

        if ($group === 'console_fast' && $rules[$group]->exactOverrideHalalah($quantity) === null) {
            $normalRule = $this->catalog->pricingRules(['console_normal'])['console_normal'];
        }

        $total = $this->calculator->calculate($rules[$group], $quantity, $normalRule);

        return new CoinsQuote(
            productId: $product->public_id,
            variantId: $variant->public_id,
            platform: $platform,
            delivery: $delivery,
            quantity: $quantity,
            total: $total,
            pricedAt: CarbonImmutable::now('UTC'),
        );
    }
}
