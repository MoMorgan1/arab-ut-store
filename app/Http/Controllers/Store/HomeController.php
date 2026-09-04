<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\ResolveCartOwner;
use App\Actions\Pricing\BuildCoinsQuoteSchedule;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Catalog\CoinsCatalogReader;
use App\Services\Content\StoreFaqReader;
use App\Services\Reviews\StoreReviewReader;
use App\Services\Store\StoreProofReader;
use App\Support\Seo\StorePageSeo;
use App\Validation\CoinsSelectionRules;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use ValueError;

class HomeController extends Controller
{
    public function __invoke(
        Request $request,
        CoinsSelectionRules $selectionRules,
        BuildCoinsQuoteSchedule $buildCoinsQuoteSchedule,
        StoreReviewReader $reviews,
        CoinsCatalogReader $catalog,
        StoreFaqReader $faqReader,
        StoreProofReader $proof,
    ): Response {
        $status = 'unavailable';
        $quoteSchedules = null;
        $quantityRules = $catalog->quantityRules();

        try {
            $displayCurrency = (string) $request->session()->get('display_currency');
            $quoteSchedules = $buildCoinsQuoteSchedule->executeHomepage($displayCurrency);
            $status = 'available';
        } catch (DomainException|ValueError) {
            // The public homepage stays available while pricing fails closed.
        }

        $locale = app()->getLocale();
        $homeReviews = $reviews->homepage($locale);
        $faqEntries = $faqReader->entries($locale);

        return Inertia::render('store/home', [
            'status' => $status,
            'coinsRequiresBalance' => $catalog->requiresCurrentBalance(),
            ...($quoteSchedules === null ? [] : ['quoteSchedules' => $quoteSchedules]),
            'quoteUrl' => $request->route('locale') === 'en'
                ? route('localized.coins.quote', ['locale' => 'en'], absolute: false)
                : route('coins.quote', absolute: false),
            'coinsCart' => [
                'addUrl' => $this->storeRoute($request, 'cart.items.coins.store'),
                'initialSelection' => $this->initialSelection($request, $selectionRules),
                'replaceCredentialsUrl' => $this->replaceCredentialsUrl($request),
            ],
            'amount' => [
                'minimum' => $quantityRules->minimum(),
                'roundingUnit' => $quantityRules->roundingUnit(),
                'tiers' => $quantityRules->tiers(),
                'presets' => $quantityRules->presets(),
            ],
            'platforms' => $this->platforms(),
            'homeContent' => [
                'services' => $this->services($request),
                'servicesTranslations' => trans('store.services_section'),
                'reviews' => $homeReviews,
                'reviewsUrl' => $this->storeRoute($request, 'store.reviews'),
                'reviewsTranslations' => trans('store.reviews'),
                'faq' => $faqEntries,
                'faqTranslations' => [
                    'eyebrow' => trans('store.faq.eyebrow'),
                    'title' => trans('store.faq.title'),
                ],
            ],
            'store' => $this->storeTranslations($proof),
            'seo' => StorePageSeo::default()
                ->withHome($locale)
                ->withRating($homeReviews['average'], $homeReviews['count'])
                ->withReviews($homeReviews['items'])
                ->withFaq($faqEntries)
                ->toArray(),
        ]);
    }

    /**
     * The store copy, with the counted hero proof filled from the database.
     *
     * @return array<string, mixed>
     */
    private function storeTranslations(StoreProofReader $proof): array
    {
        $store = trans('store');

        if (! is_array($store) || ! is_array($store['hero'] ?? null) || ! is_array($store['hero']['stats'] ?? null)) {
            throw new LogicException('The store translations must define hero stats.');
        }

        $store['hero']['stats'] = $proof->heroStats(array_values($store['hero']['stats']));

        return $store;
    }

    /** @return list<array<string, mixed>> */
    private function services(Request $request): array
    {
        return [
            $this->serviceCard($request, 'sbc', 'store.sbc', '/images/store/services/sbc.webp'),
            $this->serviceCard($request, 'objectives', 'store.objectives', '/images/store/services/objectives.webp'),
            $this->serviceCard($request, 'fut_champions', 'store.fut_champions', '/images/store/services/fut-champions.webp'),
            $this->serviceCard($request, 'rivals', 'store.rivals', '/images/store/services/rivals.webp'),
            [
                'key' => 'sell_coins',
                'title' => $this->translation('store.services.sell_coins.title'),
                'description' => $this->translation('store.services.sell_coins.card_description'),
                'href' => 'https://sell.arab-ut.com/',
                'imageUrl' => '/images/store/services/sell-coins.webp',
                'external' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function serviceCard(Request $request, string $key, string $route, string $imageUrl): array
    {
        return [
            'key' => $key,
            'title' => $this->translation("store.services.{$key}.title"),
            'description' => $this->translation("store.services.{$key}.card_description"),
            'href' => $this->storeRoute($request, $route),
            'imageUrl' => $imageUrl,
            'external' => false,
        ];
    }

    private function storeRoute(Request $request, string $route): string
    {
        return $request->route('locale') === 'en'
            ? route("localized.{$route}", ['locale' => 'en'], absolute: false)
            : route($route, absolute: false);
    }

    /** @return array{platform: string, delivery: string|null, quantity: int}|null */
    private function initialSelection(Request $request, CoinsSelectionRules $selectionRules): ?array
    {
        if (
            $request->user() === null
            || $request->query('step') !== 'credentials'
            || $this->queryContainsCredentials($request)
        ) {
            return null;
        }

        $selection = $request->only(['platform', 'delivery', 'quantity']);
        $validator = Validator::make(
            $selection,
            $selectionRules->for($selection['platform'] ?? null, $selection['delivery'] ?? null),
        );

        if ($validator->fails()) {
            return null;
        }

        return [
            'platform' => (string) $selection['platform'],
            'delivery' => isset($selection['delivery']) ? (string) $selection['delivery'] : null,
            'quantity' => (int) $selection['quantity'],
        ];
    }

    /**
     * The owner-scoped credentials URL for the cart line being edited, if the
     * `replace` query value names a line on the visitor's own active cart.
     * Built server-side so the client never assembles credential URLs.
     */
    private function replaceCredentialsUrl(Request $request): ?string
    {
        $replace = $request->query('replace');

        if (! is_string($replace) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/D', $replace) !== 1) {
            return null;
        }

        $ownedCartIds = Cart::query();
        $ownedCartIds->activeForOwner(app(ResolveCartOwner::class)->forRequest($request));

        $item = CartItem::query()
            ->where('public_id', $replace)
            ->whereIn('cart_id', $ownedCartIds->select('id'))
            ->first();

        if (! $item instanceof CartItem) {
            return null;
        }

        $localized = $request->route('locale') === 'en';

        return route(
            $localized ? 'localized.cart.items.credentials.show' : 'cart.items.credentials.show',
            [...($localized ? ['locale' => 'en'] : []), 'cartItem' => $item->public_id],
            absolute: false,
        );
    }

    private function queryContainsCredentials(Request $request): bool
    {
        foreach (['credentials', 'ea_email', 'ea_password', 'backup_codes'] as $field) {
            if ($request->query->has($field)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function platforms(): array
    {
        $platforms = [];

        foreach ([Platform::PlayStation, Platform::Pc] as $platform) {
            $platforms[] = [
                'value' => $platform->value,
                'label' => $this->translation("store.platform.descriptions.{$platform->value}"),
                'iconUrls' => Config::array("coins.platforms.{$platform->value}.icon_urls"),
                'maximum' => Config::integer("coins.platforms.{$platform->value}.maximum"),
                'deliveries' => $this->deliveries($platform),
            ];
        }

        return $platforms;
    }

    /** @return list<array{value: string, label: string, maximum: int, minutesPerMillion: int}> */
    private function deliveries(Platform $platform): array
    {
        $configuredDeliveries = Config::array("coins.platforms.{$platform->value}.deliveries");
        $deliveries = [];

        foreach (array_keys($configuredDeliveries) as $delivery) {
            if (! is_string($delivery)) {
                throw new LogicException('A configured Coins delivery key must be a string.');
            }

            $prefix = "coins.platforms.{$platform->value}.deliveries.{$delivery}";
            $deliveries[] = [
                'value' => $delivery,
                'label' => $this->translation("store.delivery.options.{$delivery}"),
                'maximum' => Config::integer("{$prefix}.maximum"),
                'minutesPerMillion' => Config::integer("{$prefix}.minutes_per_million"),
            ];
        }

        return $deliveries;
    }

    private function translation(string $key): string
    {
        $translation = trans($key);

        if (! is_string($translation)) {
            throw new LogicException("The store translation [{$key}] must be a string.");
        }

        return $translation;
    }
}
