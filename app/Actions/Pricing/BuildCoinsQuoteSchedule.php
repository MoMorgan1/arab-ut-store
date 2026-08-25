<?php

namespace App\Actions\Pricing;

use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CoinsCatalogReader;
use App\Services\Pricing\CoinsPriceCalculator;
use App\ValueObjects\Pricing\CoinsPricingRule;
use App\ValueObjects\Pricing\CoinsQuoteScheduleContext;
use App\ValueObjects\Pricing\PreparedDisplayMoneyConverter;
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
        $context = $this->loadPricingContextOnce($platform, $delivery, $displayCurrency);

        return $this->build($context, $maximum, now('UTC')->toIso8601String());
    }

    /** @return array{'playstation:normal': array<string, mixed>, 'playstation:fast': array<string, mixed>, pc: array<string, mixed>} */
    public function executeHomepage(string $displayCurrency): array
    {
        $contexts = $this->loadHomepageContexts($displayCurrency);
        $pricedAt = now('UTC')->toIso8601String();

        return [
            'playstation:normal' => $this->build(
                $contexts['playstation:normal'],
                Config::integer('coins.platforms.playstation.deliveries.normal.maximum'),
                $pricedAt,
            ),
            'playstation:fast' => $this->build(
                $contexts['playstation:fast'],
                Config::integer('coins.platforms.playstation.deliveries.fast.maximum'),
                $pricedAt,
            ),
            'pc' => $this->build(
                $contexts['pc'],
                Config::integer('coins.platforms.pc.maximum'),
                $pricedAt,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function build(CoinsQuoteScheduleContext $context, int $maximum, string $pricedAt): array
    {
        $rules = $this->catalog->quantityRules();
        $minimum = $rules->minimum();

        if ($maximum < $minimum || ! $rules->accepts($maximum)) {
            throw new DomainException('The Coins quote schedule bounds are invalid.');
        }

        // A platform or delivery speed may cap below the catalogue ceiling —
        // normal console delivery stops at two million — so the schedule is the
        // slider stops up to this variant's own maximum, no further.
        $quantities = array_values(array_filter(
            $rules->sliderStops(),
            static fn (int $quantity): bool => $quantity <= $maximum,
        ));
        $expectedLength = count($quantities);
        $totalsHalalah = [];
        $displayTotalsMinor = [];

        foreach ($quantities as $quantity) {
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
            'platform' => $context->platform->value,
            'delivery' => $context->delivery?->value,
            'market' => $context->platform->market()->value,
            'minimum' => $minimum,
            'maximum' => $maximum,
            'quantities' => $quantities,
            'productId' => $context->productId,
            'variantId' => $context->variantId,
            'priceVersion' => $context->priceVersion,
            'pricedAt' => $pricedAt,
            'displayCurrency' => $context->displayConverter->currency,
            'totalsHalalah' => $totalsHalalah,
            'displayTotalsMinor' => $displayTotalsMinor,
        ];
    }

    private function loadPricingContextOnce(
        Platform $platform,
        ?DeliveryMode $delivery,
        string $displayCurrency,
    ): CoinsQuoteScheduleContext {
        $this->assertDeliveryMatchesPlatform($platform, $delivery);
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

        return $this->makeContext(
            $product,
            $variant,
            $platform,
            $delivery,
            $rules[$group],
            $normalRule,
            $displayConverter,
            $displayCurrency,
        );
    }

    /** @return array{'playstation:normal': CoinsQuoteScheduleContext, 'playstation:fast': CoinsQuoteScheduleContext, pc: CoinsQuoteScheduleContext} */
    private function loadHomepageContexts(string $displayCurrency): array
    {
        $product = $this->catalog->product();
        $this->catalog->assertHomepageProduct($product);
        $playStation = $this->catalog->variant($product, Platform::PlayStation);
        $pc = $this->catalog->variant($product, Platform::Pc);
        $rules = $this->catalog->pricingRules(['console_normal', 'console_fast', 'pc']);
        $displayConverter = $this->convertDisplayMoney->prepare($displayCurrency);

        return [
            'playstation:normal' => $this->makeContext(
                $product,
                $playStation,
                Platform::PlayStation,
                DeliveryMode::Normal,
                $rules['console_normal'],
                null,
                $displayConverter,
                $displayCurrency,
            ),
            'playstation:fast' => $this->makeContext(
                $product,
                $playStation,
                Platform::PlayStation,
                DeliveryMode::Fast,
                $rules['console_fast'],
                $rules['console_normal'],
                $displayConverter,
                $displayCurrency,
            ),
            'pc' => $this->makeContext(
                $product,
                $pc,
                Platform::Pc,
                null,
                $rules['pc'],
                null,
                $displayConverter,
                $displayCurrency,
            ),
        ];
    }

    private function makeContext(
        Product $product,
        ProductVariant $variant,
        Platform $platform,
        ?DeliveryMode $delivery,
        CoinsPricingRule $rule,
        ?CoinsPricingRule $normalRule,
        PreparedDisplayMoneyConverter $displayConverter,
        string $displayCurrency,
    ): CoinsQuoteScheduleContext {
        $this->assertDeliveryMatchesPlatform($platform, $delivery);

        $expectedGroup = match (true) {
            $platform === Platform::Pc => 'pc',
            $delivery === DeliveryMode::Normal => 'console_normal',
            default => 'console_fast',
        };

        if (trim($product->public_id) === ''
            || trim($variant->public_id) === ''
            || $variant->price_version <= 0
            || $variant->platform !== $platform
            || $variant->market !== $platform->market()
            || $rule->group !== $expectedGroup
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
            rule: $rule,
            normalRule: $normalRule,
            displayConverter: $displayConverter,
        );
    }

    private function assertDeliveryMatchesPlatform(Platform $platform, ?DeliveryMode $delivery): void
    {
        if (($platform === Platform::Pc && $delivery !== null)
            || ($platform !== Platform::Pc && $delivery === null)) {
            throw new DomainException('The delivery mode does not match the selected platform.');
        }
    }
}
