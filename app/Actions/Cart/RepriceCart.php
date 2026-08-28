<?php

namespace App\Actions\Cart;

use App\Actions\Pricing\QuoteCoins;
use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\CartItemUnavailableReason;
use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\ValueObjects\Cart\CartItemPrice;
use App\ValueObjects\Cart\CartRepricing;
use App\ValueObjects\Pricing\SbcCompletionPricing;
use DomainException;

/**
 * The single answer to "what does this cart item cost right now".
 *
 * Both the cart page and checkout run through here, so a customer can never be
 * shown one price and charged another by two code paths drifting apart. Pass
 * lock: true on the checkout path only - the render path must not take row
 * locks on a GET.
 */
final readonly class RepriceCart
{
    public function __construct(
        private QuoteCoins $quoteCoins,
        private ReadManualServicePricing $readManualServicePricing,
    ) {}

    public function execute(Cart $cart, bool $lock = false): CartRepricing
    {
        $prices = [];

        foreach ($cart->items as $item) {
            $prices[(int) $item->id] = $this->priceItem($item, $lock);
        }

        return new CartRepricing($prices);
    }

    private function priceItem(CartItem $item, bool $lock): CartItemPrice
    {
        $configuration = $item->configuration;

        if (! is_array($configuration)
            || ! isset($configuration['service_type'], $configuration['platform'], $configuration['price_version'])
            || ! is_string($configuration['service_type'])
            || ! is_string($configuration['platform'])
            || ! is_int($configuration['price_version'])) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::ConfigurationInvalid);
        }

        $service = ServiceType::tryFrom($configuration['service_type']);
        $platform = Platform::tryFrom($configuration['platform']);

        if (! $service instanceof ServiceType || ! $platform instanceof Platform) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::ConfigurationInvalid);
        }

        $query = ProductVariant::query()
            ->whereKey($item->product_variant_id)
            ->where('is_active', true)
            ->with('product.category');

        if ($lock) {
            $query->lockForUpdate();
        }

        $variant = $query->first();

        if (! $variant instanceof ProductVariant) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::VariantInactive);
        }

        if (! $variant->product instanceof Product || ! $variant->product->isStorefrontVisible()) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::ProductHidden);
        }

        if ($variant->service_type !== $service
            || $variant->product->service_type !== $service
            || $variant->platform !== $platform) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::VariantInactive);
        }

        return match (true) {
            $service === ServiceType::Coins => $this->coins($variant, $platform, $configuration),
            $this->isManualService($service) => $this->manualService($variant, $service, $platform, $configuration, $lock),
            $service === ServiceType::Sbc => $this->sbc($variant, $configuration),
            default => CartItemPrice::priced(
                $variant->effectivePriceHalalah(),
                $variant->effectivePriceHalalah() * (int) $item->quantity,
                (int) $variant->price_version,
                $variant,
                quotedAt: $this->quotedAt(),
            ),
        };
    }

    /** @param array<string, mixed> $configuration */
    private function coins(ProductVariant $variant, Platform $platform, array $configuration): CartItemPrice
    {
        $quantity = $configuration['coins_quantity'] ?? null;
        $delivery = $configuration['delivery'] ?? null;

        if (! is_int($quantity)) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::ConfigurationInvalid);
        }

        $deliveryMode = is_string($delivery) ? DeliveryMode::tryFrom($delivery) : null;

        // QuoteCoins treats a delivery mode that does not match the platform as
        // a programming error and throws InvalidArgumentException, so a cart row
        // carrying a malformed delivery value has to be rejected here rather
        // than reaching it.
        if (($platform === Platform::Pc) !== ($deliveryMode === null)) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::ConfigurationInvalid);
        }

        try {
            $quote = $this->quoteCoins->execute($platform, $deliveryMode, $quantity);
        } catch (DomainException) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::TierRemoved);
        }

        // Two very different facts share one comparison in the original code.
        // A pricing run only ever increments price_version on the rows it
        // already holds, so a differing version against the same variant is a
        // run committing right now - transient. A differing variant id is the
        // permanent case: the old variant was deactivated and replaced, and
        // routing that to a "try again shortly" message would never clear.
        if ($quote->variantId !== $variant->public_id) {
            return CartItemPrice::priced(
                $quote->total->halalah(),
                $quote->total->halalah(),
                $quote->priceVersion,
                $variant,
                quotedAt: $this->quotedAt(),
            );
        }

        if ($quote->priceVersion !== (int) $variant->price_version) {
            return CartItemPrice::pricingRunInProgress();
        }

        return CartItemPrice::priced(
            $quote->total->halalah(),
            $quote->total->halalah(),
            $quote->priceVersion,
            $variant,
            quotedAt: $this->quotedAt(),
        );
    }

    /** @param array<string, mixed> $configuration */
    private function sbc(ProductVariant $variant, array $configuration): CartItemPrice
    {
        $completionCount = $configuration['completion_count'] ?? null;

        if (! is_int($completionCount) || $completionCount < 1 || $completionCount > 100) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::ConfigurationInvalid);
        }

        try {
            $pricing = SbcCompletionPricing::fromConfiguration(
                $variant->effectivePricingConfiguration(),
                $variant->effectivePriceHalalah(),
                requireDeclared: false,
            );
        } catch (DomainException) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::ConfigurationInvalid);
        }

        $tierTotal = $pricing->tierTotal($completionCount);

        if ($tierTotal === null) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::TierRemoved);
        }

        return CartItemPrice::priced(
            $tierTotal,
            $tierTotal,
            (int) $variant->price_version,
            $variant,
            quotedAt: $this->quotedAt(),
        );
    }

    /** @param array<string, mixed> $configuration */
    private function manualService(
        ProductVariant $variant,
        ServiceType $service,
        Platform $platform,
        array $configuration,
        bool $lock,
    ): CartItemPrice {
        if (! in_array($platform, [Platform::PlayStation, Platform::Pc], true)
            || ! $this->validManualConfiguration($configuration, $service, $platform)) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::ConfigurationInvalid);
        }

        try {
            if ($service === ServiceType::FutChampions) {
                $pricing = $this->readManualServicePricing->futChampions($lock);
                $schedule = $pricing['schedule'];
                $total = $pricing['pricing']->priceForRank($configuration['rank'], $configuration['urgent']);
            } else {
                $pricing = $this->readManualServicePricing->rivals($lock);
                $schedule = $pricing['schedule'];
                $total = $configuration['mode'] === 'weekly_matches'
                    ? $pricing['pricing']->weeklyMatchesPriceHalalah()
                    : $pricing['pricing']->priceForRoute(
                        $configuration['current_division'],
                        $configuration['target_division'],
                    );
            }
        } catch (DomainException) {
            return CartItemPrice::unavailable(CartItemUnavailableReason::ScheduleRouteRemoved);
        }

        return CartItemPrice::priced(
            $total,
            $total,
            (int) $schedule->version,
            $variant,
            scheduleVersion: (int) $schedule->version,
            quotedAt: $this->quotedAt(),
        );
    }

    /** @param array<string, mixed> $configuration */
    private function validManualConfiguration(
        array $configuration,
        ServiceType $service,
        Platform $platform,
    ): bool {
        $common = [
            'service_type', 'platform', 'market', 'pc_store', 'quoted_at', 'price_version', 'schedule_version',
        ];
        $expected = $service === ServiceType::FutChampions
            ? [...$common, 'rank', 'urgent', 'matches_played']
            : [...$common, 'mode', 'current_division', 'target_division', 'included_wins'];
        $actual = array_keys($configuration);
        sort($actual);
        sort($expected);

        if ($actual !== $expected
            || $configuration['market'] !== $platform->market()->value
            || ! is_int($configuration['price_version'])
            || ! is_int($configuration['schedule_version'])
            || ! is_string($configuration['quoted_at'])
            || ($platform === Platform::PlayStation && $configuration['pc_store'] !== null)
            || ($platform === Platform::Pc && ! in_array($configuration['pc_store'], ['ea_app', 'steam'], true))) {
            return false;
        }

        if ($service === ServiceType::FutChampions) {
            return is_int($configuration['rank'])
                && $configuration['rank'] >= 1
                && $configuration['rank'] <= 6
                && is_bool($configuration['urgent'])
                && is_int($configuration['matches_played'])
                && $configuration['matches_played'] >= 0
                && $configuration['matches_played'] <= 100;
        }

        if ($configuration['mode'] === 'weekly_matches') {
            return $configuration['current_division'] === null
                && $configuration['target_division'] === null
                && is_int($configuration['included_wins'])
                && $configuration['included_wins'] > 0;
        }

        return $configuration['mode'] === 'promotion'
            && is_string($configuration['current_division'])
            && is_string($configuration['target_division'])
            && $configuration['included_wins'] === null;
    }

    private function isManualService(ServiceType $service): bool
    {
        return in_array($service, [ServiceType::FutChampions, ServiceType::Rivals], true);
    }

    private function quotedAt(): string
    {
        return now()->toIso8601String();
    }
}
