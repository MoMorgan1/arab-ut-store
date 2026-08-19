<?php

namespace App\Http\Middleware;

use App\Actions\Cart\ResolveCartOwner;
use App\Models\Cart;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private readonly ResolveCartOwner $resolveCartOwner) {}

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

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'displayCurrency' => $request->session()->get('display_currency'),
            'displayCurrencies' => config('store.display_currencies'),
            'checkoutCurrency' => config('store.checkout_currency'),
            'cartCount' => $this->cartCount($request),
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
            'chat' => [
                'enabled' => (bool) config('chat.enabled', false),
                'demoAssistant' => (bool) config('chat.demo_assistant', false),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    private function cartCount(Request $request): int
    {
        $activeCart = Cart::query()
            ->activeForOwner($this->resolveCartOwner->forRequest($request))
            ->withCount('items')
            ->first();

        return $activeCart->items_count ?? 0;
    }
}
