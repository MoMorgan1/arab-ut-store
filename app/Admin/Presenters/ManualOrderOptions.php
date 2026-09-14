<?php

namespace App\Admin\Presenters;

use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\DeliveryPhase;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Everything the manual-order drawer needs to render its choices.
 *
 * Fetched when the drawer opens rather than shipped with the orders page: a
 * manual order is rare (owner, 2026-09-13 - "المانوال اصلا كل فين وفين") and
 * the orders list is one of the most-loaded screens in the admin, so the
 * catalogue has no business riding along on every page view of it.
 *
 * Each service says what it needs and what it refuses, so the drawer never has
 * to hold a second copy of rules that live in `CreateManualOrderRequest`.
 */
final readonly class ManualOrderOptions
{
    /** SBC is sold as named challenges, so the drawer lists them to pick from. */
    private const VARIANT_LIMIT = 200;

    public function __construct(
        private ReadManualServicePricing $readManualServicePricing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(string $locale): array
    {
        return [
            'services' => array_map(
                fn (ServiceType $service): array => [
                    'value' => $service->value,
                    'label' => (string) trans("admin.orders.services.{$service->value}", locale: $locale),
                    'platforms' => array_map(
                        fn (Platform $platform): string => $platform->value,
                        $this->platformsFor($service),
                    ),
                    // Only Coins and SBC are delivered by a supplier bot, so
                    // only they can carry a reference - the same rule
                    // `CreateManualOrderRequest::validatePlacement` enforces.
                    'acceptsPlacement' => in_array($service, [ServiceType::Coins, ServiceType::Sbc], true),
                ],
                ServiceType::cases(),
            ),
            'platforms' => array_map(
                fn (Platform $platform): array => [
                    'value' => $platform->value,
                    'label' => (string) trans("admin.orders.platforms.{$platform->value}", locale: $locale),
                ],
                Platform::cases(),
            ),
            'suppliers' => array_map(
                fn (Supplier $supplier): array => [
                    'value' => $supplier->value,
                    'label' => mb_strtoupper($supplier->value),
                    'handlesChallenges' => $supplier->handlesChallenges(),
                ],
                Supplier::cases(),
            ),
            'deliveryPhases' => array_map(
                fn (DeliveryPhase $phase): array => [
                    'value' => $phase->value,
                    'label' => (string) trans("admin.orders.manual.phases.{$phase->value}", locale: $locale),
                ],
                DeliveryPhase::cases(),
            ),
            'sbcVariants' => $this->sbcVariants(),
            'rivals' => $this->rivals(),
            'futChampions' => $this->futChampions(),
        ];
    }

    /**
     * The booster-configured services are sold on PlayStation and PC only -
     * `ManualServiceCartSupport::eligibleVariant` throws for anything else, so
     * an Xbox Rivals order could never be fulfilled and is not offered.
     *
     * @return list<Platform>
     */
    private function platformsFor(ServiceType $service): array
    {
        return $service->isBoosterConfigured()
            ? [Platform::PlayStation, Platform::Pc]
            : Platform::cases();
    }

    /**
     * @return list<array{publicId: string, name: string, platform: string, priceHalalah: int}>
     */
    private function sbcVariants(): array
    {
        // whereHas('product') is why the name below is always there: a variant
        // whose product is missing or hidden is not on sale and not listed.
        $variants = ProductVariant::query()
            ->where('service_type', ServiceType::Sbc)
            ->where('is_active', true)
            ->whereHas('product', function (Builder $query): void {
                $query->where('service_type', ServiceType::Sbc);
                Product::applyStorefrontVisible($query);
            })
            ->with('product')
            ->orderBy('platform')
            ->limit(self::VARIANT_LIMIT)
            ->get()
            ->all();

        return array_values(array_map(
            fn (ProductVariant $variant): array => [
                'publicId' => $variant->public_id,
                'name' => $variant->product->name_en,
                'platform' => $variant->platform->value,
                'priceHalalah' => $variant->effectivePriceHalalah(),
            ],
            $variants,
        ));
    }

    /**
     * Null when the pricing page is inactive. `ReadManualServicePricing`
     * throws in that case, and the storefront hides the service rather than
     * selling at a number nobody chose - so the drawer hides it too.
     *
     * @return array{divisions: list<string>, offersWeeklyMatches: bool}|null
     */
    private function rivals(): ?array
    {
        try {
            $pricing = $this->readManualServicePricing->rivals()['pricing'];
        } catch (Throwable) {
            return null;
        }

        return [
            'divisions' => ['7', '6', '5', '4', '3', '2', '1', 'elite'],
            'offersWeeklyMatches' => $pricing->offersWeeklyMatches(),
        ];
    }

    /**
     * @return array{ranks: list<int>}|null
     */
    private function futChampions(): ?array
    {
        try {
            $this->readManualServicePricing->futChampions();
        } catch (Throwable) {
            return null;
        }

        // 1 to 6, as `FutChampionsCartRequest:26` accepts.
        return ['ranks' => [1, 2, 3, 4, 5, 6]];
    }
}
