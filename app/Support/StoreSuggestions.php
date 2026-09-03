<?php

namespace App\Support;

use App\Actions\Catalog\StoreCatalogReader;
use App\Enums\ServiceType;
use App\Models\Cart;
use DomainException;
use Illuminate\Http\Request;

/**
 * "Usually ordered together" suggestions for the cart page, plus the related
 * services block on the manual-service pages. Both read the public SBC
 * catalog in recommended order; a catalog outage degrades to fewer cards
 * rather than breaking the page.
 *
 * Extensible rather than final so tests can override the catalog hook to
 * simulate an outage (the catalog reader itself is final and unmockable).
 */
class StoreSuggestions
{
    public function __construct(private readonly StoreCatalogReader $catalog) {}

    /**
     * Up to eight public SBC products in the reader's public catalog shape
     * (the same one the SBC product page renders), plus the other manual service.
     *
     * @return array{products: list<array<string, mixed>>, sbcUrl: string, service: array{key: string, title: string, description: string, href: string, imageUrl: string}}
     */
    public function forManualService(Request $request, ServiceType $service): array
    {
        $displayCurrency = (string) ($request->session()->get('display_currency') ?? config('store.default_display_currency'));

        try {
            $sbcProducts = array_slice($this->readSbcProducts(app()->getLocale(), $displayCurrency), 0, 8);
        } catch (DomainException) {
            $sbcProducts = [];
        }

        $otherServiceKey = $service === ServiceType::FutChampions ? 'rivals' : 'fut_champions';
        $otherServiceRoute = $service === ServiceType::FutChampions ? 'store.rivals' : 'store.fut_champions';
        $otherServiceImage = $service === ServiceType::FutChampions
            ? '/images/store/services/rivals.webp'
            : '/images/store/services/fut-champions.webp';

        return [
            'products' => $sbcProducts,
            'sbcUrl' => $this->route($request, 'store.sbc'),
            'service' => [
                'key' => $otherServiceKey,
                'title' => trans("store.services.{$otherServiceKey}.title"),
                'description' => trans("store.services.{$otherServiceKey}.card_description"),
                'href' => $this->route($request, $otherServiceRoute),
                'imageUrl' => $otherServiceImage,
            ],
        ];
    }

    /**
     * Up to four cards for the cart page: SBC products first (recommended
     * order, excluding anything already in the cart), then the manual
     * services not yet in the cart. The first card carries a reason tag
     * derived from the first cart line's service type.
     *
     * @return array{products: list<array<string, mixed>>, services: list<array{key: string, title: string, description: string, href: string, imageUrl: string}>, reason: string|null, sbcUrl: string}
     */
    public function forCart(?Cart $cart, Request $request, string $locale, string $displayCurrency): array
    {
        $sbcUrl = $this->route($request, 'store.sbc');

        if (! $cart instanceof Cart || $cart->items->isEmpty()) {
            return ['products' => [], 'services' => [], 'reason' => null, 'sbcUrl' => $sbcUrl];
        }

        $cartServiceTypes = $cart->items
            ->map(fn ($item): ?string => $item->productVariant?->service_type->value
                ?? (is_string($item->configuration['service_type'] ?? null) ? $item->configuration['service_type'] : null))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $cartSbcIds = $cart->items
            ->map(fn ($item): ?string => $item->productVariant?->product?->public_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $products = [];

        try {
            foreach ($this->readSbcProducts($locale, $displayCurrency) as $product) {
                if (in_array($product['id'] ?? null, $cartSbcIds, true)) {
                    continue;
                }

                $products[] = $product;

                if (count($products) >= 2) {
                    break;
                }
            }
        } catch (DomainException) {
            $products = [];
        }

        $services = [];

        foreach (['rivals', 'fut_champions'] as $serviceKey) {
            if (in_array($serviceKey, $cartServiceTypes, true)) {
                continue;
            }

            $services[] = [
                'key' => $serviceKey,
                'title' => trans("store.services.{$serviceKey}.title"),
                'description' => trans("store.services.{$serviceKey}.card_description"),
                'href' => $this->route($request, $serviceKey === 'rivals' ? 'store.rivals' : 'store.fut_champions'),
                'imageUrl' => $serviceKey === 'rivals'
                    ? '/images/store/services/rivals.webp'
                    : '/images/store/services/fut-champions.webp',
            ];
        }

        return [
            'products' => $products,
            'services' => $services,
            'reason' => $this->reasonFor($cartServiceTypes[0] ?? null),
            'sbcUrl' => $sbcUrl,
        ];
    }

    private function reasonFor(?string $serviceType): ?string
    {
        return match ($serviceType) {
            ServiceType::Coins->value => (string) trans('store.cart_page.suggestions.reason_coins'),
            ServiceType::Rivals->value => (string) trans('store.cart_page.suggestions.reason_rivals'),
            ServiceType::FutChampions->value => (string) trans('store.cart_page.suggestions.reason_fut'),
            ServiceType::Sbc->value => (string) trans('store.cart_page.suggestions.reason_sbc'),
            default => null,
        };
    }

    /**
     * The public SBC catalog in recommended order, shared by both blocks.
     * Separated for testability: the catalog reader is final, so tests
     * override this hook instead of mocking the reader.
     *
     * @return list<array<string, mixed>>
     */
    protected function readSbcProducts(string $locale, string $displayCurrency): array
    {
        $catalog = $this->catalog->category(
            ServiceType::Sbc,
            $locale,
            $displayCurrency,
            'all',
            'recommended',
            '',
            1,
        );

        return $catalog['products'];
    }

    /** @param array<string, mixed> $parameters */
    private function route(Request $request, string $name, array $parameters = []): string
    {
        $localized = $request->route('locale') === 'en';

        return route(
            $localized ? "localized.{$name}" : $name,
            ($localized ? ['locale' => 'en'] : []) + $parameters,
            absolute: false,
        );
    }
}
