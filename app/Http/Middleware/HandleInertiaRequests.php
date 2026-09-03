<?php

namespace App\Http\Middleware;

use App\Actions\Cart\ResolveCartOwner;
use App\Models\Cart;
use App\Services\Catalog\CoinsCatalogReader;
use App\Support\Seo\StorePageSeo;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly ResolveCartOwner $resolveCartOwner,
        private readonly CoinsCatalogReader $coinsCatalog,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();
        $localized = $locale === 'en';
        $routeParameters = $localized ? ['locale' => $locale] : [];
        $routeName = fn (string $name): string => $localized ? "localized.{$name}" : $name;
        $storeUrl = fn (string $name): string => route($routeName("store.{$name}"), $routeParameters, absolute: false);
        $homeUrl = route($routeName('home'), $routeParameters, absolute: false);
        $cartSummary = $this->cartSummary($request);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'displayCurrency' => $request->session()->get('display_currency'),
            'displayCurrencies' => config('store.display_currencies'),
            'checkoutCurrency' => config('store.checkout_currency'),
            'cartCount' => $cartSummary['count'],
            'cartVariantIds' => $cartSummary['variantIds'],
            'ui' => trans('ui'),
            'storeShell' => [
                'homeUrl' => $homeUrl,
                'coinsUrl' => "{$homeUrl}#coins",
                'cartUrl' => $storeUrl('cart'),
                'sbcUrl' => $storeUrl('sbc'),
                'futChampionsUrl' => $storeUrl('fut_champions'),
                'accountUrl' => $request->user() === null
                    ? route($routeName('login'), $routeParameters, absolute: false)
                    : route(
                        $localized ? 'localized.account.overview' : 'account.overview',
                        absolute: false,
                    ),
                'privacyUrl' => $storeUrl('privacy'),
                'returnsUrl' => $storeUrl('returns'),
                'warrantyUrl' => $storeUrl('warranty'),
                'eaBackupCodesUrl' => $storeUrl('ea_backup_codes'),
                'termsUrl' => $storeUrl('terms'),
                'whatsappUrl' => config('store.support.whatsapp_url'),
                'email' => config('store.support.email'),
                'socials' => config('store.socials'),
                'payments' => array_map(
                    fn (array $payment): array => [
                        'name' => $payment['name'],
                        'imageUrl' => $payment['image_url'],
                        'width' => $payment['width'],
                        'height' => $payment['height'],
                    ],
                    config('store.payments'),
                ),
            ],
            'auth' => [
                'user' => $request->user(),
            ],
            // Flash feedback for one-shot actions (e.g. verification-link-sent).
            'status' => $request->session()->get('status'),
            'chat' => [
                'enabled' => (bool) config('chat.enabled', false),
            ],
            // Shared, not per-page: the chat widget mounts on every storefront
            // page and must agree with the cart endpoint's live admin toggle,
            // including for offers stored in chat history before a flip.
            'coinsRequiresBalance' => $this->coinsCatalog->requiresCurrentBalance(),
            // Storefront defaults, so every page renders valid social metadata
            // server-side. Controllers with richer data override this prop.
            'seo' => StorePageSeo::default()->toArray(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /** @return array{count: int, variantIds: string[]} */
    private function cartSummary(Request $request): array
    {
        $activeCart = Cart::query()
            ->activeForOwner($this->resolveCartOwner->forRequest($request))
            ->first();

        if ($activeCart === null) {
            return ['count' => 0, 'variantIds' => []];
        }

        // One query reads the variants on the active cart's lines; the count
        // is the row count, not distinct ids, so legacy carts that already
        // hold duplicates keep their badge number.
        $variantIds = $activeCart->items()
            ->join(
                'product_variants',
                'product_variants.id',
                '=',
                'cart_items.product_variant_id',
            )
            ->orderBy('cart_items.id')
            ->pluck('product_variants.public_id')
            ->all();

        // Row count, not distinct ids: carts from before the one-per-variant
        // rule may still hold two lines of the same variant.
        return [
            'count' => count($variantIds),
            'variantIds' => array_values(array_unique($variantIds)),
        ];
    }
}
