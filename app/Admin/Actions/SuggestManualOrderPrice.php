<?php

namespace App\Admin\Actions;

use App\Actions\Pricing\QuoteCoins;
use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\ValueObjects\Pricing\SbcCompletionPricing;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Throwable;

/**
 * What the store would have charged for this item, had the customer bought it.
 *
 * The manual-order drawer suggests a price and lets staff change it (owner
 * decision, 2026-09-13), so this exists to make the suggestion the same number
 * the storefront would have quoted rather than a second opinion about it: every
 * branch below reads through the same pricing the matching Add*ToCart action
 * reads, and nothing here computes a price of its own.
 *
 * A suggestion is a courtesy, never a gate. Anything this cannot price comes
 * back as a null price with a reason, and the person filling the form types the
 * figure - which is also the only thing that works for Objectives, a service
 * with no schedule and no quote path at all.
 */
final readonly class SuggestManualOrderPrice
{
    public function __construct(
        private QuoteCoins $quoteCoins,
        private ReadManualServicePricing $readManualServicePricing,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{price_halalah: int|null, product_variant_id: string|null, reason: string|null}
     */
    public function execute(ServiceType $service, Platform $platform, array $configuration): array
    {
        try {
            return match ($service) {
                ServiceType::Coins => $this->coins($platform, $configuration),
                ServiceType::Sbc => $this->sbc($configuration),
                ServiceType::Rivals => $this->rivals($platform, $configuration),
                ServiceType::FutChampions => $this->futChampions($platform, $configuration),
                // Objectives is delivered by a person and priced by agreement.
                // It owns no ServicePriceSchedule (ServiceType::scheduled())
                // and no quote path, so there is nothing to read.
                ServiceType::Objectives => $this->unpriced('This service is priced by agreement, so type the figure.'),
            };
        } catch (DomainException|InvalidArgumentException $failure) {
            // A stale schedule, an option that is not on sale, a platform the
            // store does not sell: all of them mean "no suggestion", none of
            // them mean "no order". The message is written for staff.
            return $this->unpriced($failure->getMessage());
        } catch (Throwable) {
            return $this->unpriced('The catalogue could not be read just now, so type the figure.');
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{price_halalah: int|null, product_variant_id: string|null, reason: string|null}
     */
    private function coins(Platform $platform, array $configuration): array
    {
        $quantity = $configuration['coins_quantity'] ?? null;

        if (! is_int($quantity) || $quantity < 1) {
            return $this->unpriced('Enter how many thousand coins.');
        }

        // QuoteCoins:22 refuses a console without a delivery mode and a PC with
        // one, because the price table is grouped that way.
        $delivery = $platform === Platform::Pc
            ? null
            : DeliveryMode::tryFrom((string) ($configuration['delivery'] ?? ''));

        if ($platform !== Platform::Pc && ! $delivery instanceof DeliveryMode) {
            return $this->unpriced('Choose normal or fast delivery.');
        }

        $quote = $this->quoteCoins->execute($platform, $delivery, $quantity);

        return [
            'price_halalah' => $quote->total->halalah(),
            'product_variant_id' => $quote->variantId,
            'reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{price_halalah: int|null, product_variant_id: string|null, reason: string|null}
     */
    private function sbc(array $configuration): array
    {
        $completions = $configuration['completion_count'] ?? null;
        $publicId = $configuration['product_variant_id'] ?? null;

        if (! is_string($publicId) || $publicId === '') {
            return $this->unpriced('Pick the SBC from the catalogue to see its price.');
        }

        if (! is_int($completions) || $completions < 1) {
            return $this->unpriced('Enter how many challenges.');
        }

        $variant = $this->storefrontVariant(ServiceType::Sbc, fn (Builder $query): Builder => $query
            ->where('public_id', $publicId));

        if (! $variant instanceof ProductVariant) {
            return $this->unpriced('That SBC is no longer on sale.');
        }

        $base = $variant->effectivePriceHalalah();

        if ($base <= 0) {
            return $this->unpriced('That SBC has no price set.');
        }

        // requireDeclared: false is what AddSbcToCart:58 passes, so a variant
        // with no tier table still prices at count x base rather than refusing.
        $tier = SbcCompletionPricing::fromConfiguration(
            $variant->effectivePricingConfiguration(),
            $base,
            requireDeclared: false,
        )->tierTotal($completions);

        if ($tier === null) {
            return $this->unpriced('That number of challenges is not on sale.');
        }

        return [
            'price_halalah' => $tier,
            'product_variant_id' => $variant->public_id,
            'reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{price_halalah: int|null, product_variant_id: string|null, reason: string|null}
     */
    private function rivals(Platform $platform, array $configuration): array
    {
        $variant = $this->boosterVariant(ServiceType::Rivals, $platform);

        if (! $variant instanceof ProductVariant) {
            return $this->unpriced('Rivals is not sold on this platform.');
        }

        $rules = $this->readManualServicePricing->rivals()['pricing'];

        if (($configuration['mode'] ?? null) === 'weekly_matches') {
            if (! $rules->offersWeeklyMatches()) {
                return $this->unpriced('Weekly matches are not on sale.');
            }

            return [
                'price_halalah' => $rules->weeklyMatchesPriceHalalah(),
                'product_variant_id' => $variant->public_id,
                'reason' => null,
            ];
        }

        $from = $configuration['current_division'] ?? null;
        $to = $configuration['target_division'] ?? null;

        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
            return $this->unpriced('Choose the current and target divisions.');
        }

        return [
            'price_halalah' => $rules->priceForRoute($from, $to),
            'product_variant_id' => $variant->public_id,
            'reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{price_halalah: int|null, product_variant_id: string|null, reason: string|null}
     */
    private function futChampions(Platform $platform, array $configuration): array
    {
        $variant = $this->boosterVariant(ServiceType::FutChampions, $platform);

        if (! $variant instanceof ProductVariant) {
            return $this->unpriced('FUT Champions is not sold on this platform.');
        }

        $rank = $configuration['rank'] ?? null;

        if (! is_int($rank) || $rank < 1 || $rank > 6) {
            return $this->unpriced('Choose the rank.');
        }

        return [
            'price_halalah' => $this->readManualServicePricing
                ->futChampions()['pricing']
                ->priceForRank($rank, (bool) ($configuration['urgent'] ?? false)),
            'product_variant_id' => $variant->public_id,
            'reason' => null,
        ];
    }

    /**
     * The booster-configured services are sold on PlayStation and PC only, and
     * the sku map in `ManualServiceCartSupport::eligibleVariant` is what says
     * so - an Xbox request throws there. Read, not locked: this suggests a
     * price, it does not hold one.
     */
    private function boosterVariant(ServiceType $service, Platform $platform): ?ProductVariant
    {
        $sku = match ([$service, $platform]) {
            [ServiceType::FutChampions, Platform::PlayStation] => 'MANUAL_FUT_CHAMPIONS_PLAYSTATION',
            [ServiceType::FutChampions, Platform::Pc] => 'MANUAL_FUT_CHAMPIONS_PC',
            [ServiceType::Rivals, Platform::PlayStation] => 'MANUAL_RIVALS_PLAYSTATION',
            [ServiceType::Rivals, Platform::Pc] => 'MANUAL_RIVALS_PC',
            default => null,
        };

        if ($sku === null) {
            return null;
        }

        return $this->storefrontVariant($service, fn (Builder $query): Builder => $query
            ->where('sku', $sku)
            ->where('platform', $platform)
            ->where('authority', ProductAuthority::Manual));
    }

    /**
     * @param  callable(Builder<ProductVariant>): Builder<ProductVariant>  $narrow
     */
    private function storefrontVariant(ServiceType $service, callable $narrow): ?ProductVariant
    {
        /** @var ProductVariant|null $variant */
        $variant = $narrow(ProductVariant::query())
            ->where('service_type', $service)
            ->where('is_active', true)
            ->whereHas('product', function (Builder $query) use ($service): void {
                $query->where('service_type', $service);
                Product::applyStorefrontVisible($query);
            })
            ->first();

        return $variant;
    }

    /** @return array{price_halalah: null, product_variant_id: null, reason: string} */
    private function unpriced(string $reason): array
    {
        return ['price_halalah' => null, 'product_variant_id' => null, 'reason' => $reason];
    }
}
