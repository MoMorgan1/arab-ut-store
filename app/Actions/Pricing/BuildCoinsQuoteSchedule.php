<?php

namespace App\Actions\Pricing;

use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Services\Catalog\CoinsCatalogReader;
use App\Services\Pricing\CoinsPriceCalculator;
use App\ValueObjects\Pricing\CoinsQuoteScheduleContext;
use DomainException;
use Illuminate\Support\Facades\Config;

final readonly class BuildCoinsQuoteSchedule
{
    public function __construct(
        private CoinsCatalogReader $catalog,
        private CoinsPriceCalculator $calculator,
        private ConvertDisplayMoney $convertDisplayMoney,
    ) {}

    /** @return array<string, mixed> */
    public function execute(
        Platform $platform,
        ?DeliveryMode $delivery,
        int $maximum,
        string $displayCurrency,
    ): array {
        $minimum = Config::integer('coins.quantity.minimum');
        $increment = Config::integer('coins.quantity.increment');

        if ($minimum <= 0 || $increment <= 0 || $maximum < $minimum || ($maximum - $minimum) % $increment !== 0) {
            throw new DomainException('The Coins quote schedule bounds are invalid.');
        }

        $expectedLength = intdiv($maximum - $minimum, $increment) + 1;
        $context = $this->loadPricingContextOnce($platform, $delivery, $displayCurrency);
        $totalsHalalah = [];
        $displayTotalsMinor = [];
        $pricedAt = now('UTC')->toIso8601String();

        for ($index = 0; $index < $expectedLength; $index++) {
            $quantity = $minimum + ($index * $increment);
            $total = $this->calculator->calculate($context->rule, $quantity, $context->normalRule);
            $display = $context->displayConverter->convert($total);

            $totalsHalalah[] = $total->halalah();
            $displayTotalsMinor[] = $display['amountMinor'];
        }

        if (count($totalsHalalah) !== $expectedLength
            || count($displayTotalsMinor) !== $expectedLength
            || count($totalsHalalah) !== count($displayTotalsMinor)) {
            throw new DomainException('A complete Coins quote schedule is unavailable.');
        }

        return [
            'platform' => $platform->value,
            'delivery' => $delivery?->value,
            'market' => $platform->market()->value,
            'minimum' => $minimum,
            'maximum' => $maximum,
            'increment' => $increment,
            'productId' => $context->productId,
            'variantId' => $context->variantId,
            'priceVersion' => $context->priceVersion,
            'pricedAt' => $pricedAt,
            'displayCurrency' => $displayCurrency,
            'totalsHalalah' => $totalsHalalah,
            'displayTotalsMinor' => $displayTotalsMinor,
        ];
    }

    private function loadPricingContextOnce(
        Platform $platform,
        ?DeliveryMode $delivery,
        string $displayCurrency,
    ): CoinsQuoteScheduleContext {
        if (($platform === Platform::Pc && $delivery !== null)
            || ($platform !== Platform::Pc && $delivery === null)) {
            throw new DomainException('The delivery mode does not match the selected platform.');
        }

        $product = $this->catalog->product();
        $variant = $this->catalog->variant($product, $platform);
        $group = match (true) {
            $platform === Platform::Pc => 'pc',
            $delivery === DeliveryMode::Normal => 'console_normal',
            default => 'console_fast',
        };
        $rules = $this->catalog->pricingRules(
            $group === 'console_fast' ? ['console_fast', 'console_normal'] : [$group],
        );
        $normalRule = $group === 'console_fast' ? $rules['console_normal'] : null;
        $displayConverter = $this->convertDisplayMoney->prepare($displayCurrency);

        if (trim($product->public_id) === ''
            || trim($variant->public_id) === ''
            || $variant->price_version <= 0
            || $variant->platform !== $platform
            || $variant->market !== $platform->market()
            || $rules[$group]->group !== $group
            || ($normalRule !== null && $normalRule->group !== 'console_normal')
            || $displayConverter->currency !== $displayCurrency) {
            throw new DomainException('The Coins quote schedule entries are inconsistent.');
        }

        return new CoinsQuoteScheduleContext(
            productId: $product->public_id,
            variantId: $variant->public_id,
            priceVersion: $variant->price_version,
            platform: $platform,
            delivery: $delivery,
            rule: $rules[$group],
            normalRule: $normalRule,
            displayConverter: $displayConverter,
        );
    }
}
