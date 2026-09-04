<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\ResolveCartOwner;
use App\Actions\Pricing\ConvertDisplayMoney;
use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\Platform;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ServicePriceSchedule;
use App\Services\Reviews\StoreReviewReader;
use App\Support\Money;
use App\Support\Seo\StoreCanonicalUrls;
use App\Support\Seo\StorePageSeo;
use App\Support\StoreSuggestions;
use App\Support\StoreTutorials;
use App\ValueObjects\Pricing\FutChampionsPricing;
use App\ValueObjects\Pricing\PreparedDisplayMoneyConverter;
use App\ValueObjects\Pricing\RivalsPricing;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

final class ManualServiceProductController extends Controller
{
    public function __invoke(
        Request $request,
        ReadManualServicePricing $readPricing,
        ConvertDisplayMoney $convertDisplayMoney,
        StoreSuggestions $suggestions,
        StoreReviewReader $reviewReader,
    ): Response {
        $service = ServiceType::from((string) $request->route('service'));
        abort_unless(in_array($service, [ServiceType::FutChampions, ServiceType::Rivals], true), 404);

        $identity = $this->identity($service);
        $product = Product::query()
            ->where('slug', $identity['slug'])
            ->where('service_type', $service)
            ->where('authority', ProductAuthority::Manual)
            ->with([
                'media' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'variants' => fn ($query) => $query->whereIn('sku', $identity['skus'])->orderBy('id'),
            ])
            ->first();

        [$schedule, $pricing] = $this->pricing($service, $readPricing);
        $active = $schedule?->is_active === true
            && $pricing !== null
            && $product instanceof Product
            && $product->is_visible
            && $product->archived_at === null
            && $product->variants->where('is_active', true)->count() === 2;

        try {
            $displayConverter = $this->displayConverter($request, $convertDisplayMoney);
            $pricingPayload = $active && $displayConverter !== null
                ? $this->publicPricing($pricing, $displayConverter)
                : null;
        } catch (DomainException) {
            // The page stays reachable while pricing fails closed.
            $pricingPayload = null;
        }

        $locale = app()->getLocale();
        $reviewsData = $reviewReader->service($service, $locale);
        $serviceName = (string) trans("store.reviews.service_names.{$service->value}");
        $serviceReviews = $reviewsData !== null ? [
            'service' => $service->value,
            'title' => (string) trans('store.reviews.service_title', ['service' => $serviceName]),
            'hint' => (string) trans('store.reviews.service_hint', ['service' => $serviceName]),
            'readAll' => (string) trans('store.reviews.service_read_all', ['service' => $serviceName]),
            'readAllUrl' => $this->route($request, 'store.reviews', ['service' => $service->value]),
            'reviews' => $reviewsData,
            'translations' => (array) trans('store.reviews'),
        ] : null;
        $productPayload = $this->product($product, $service, $identity['slug']);

        return Inertia::render('store/manual-service', [
            'backUrl' => $this->route($request, 'home').'#services',
            'replaceCredentialsUrl' => $this->replaceCredentialsUrl($request),
            'seo' => StorePageSeo::default(
                trans("store.manual_services.{$service->value}.title"),
            )
                ->withService(
                    (string) ($productPayload['name'] ?? trans("store.manual_services.{$service->value}.title")),
                    isset($productPayload['description']) ? (string) $productPayload['description'] : null,
                )
                ->withRating($reviewsData['average'] ?? null, (int) ($reviewsData['count'] ?? 0))
                ->withReviews($reviewsData['items'] ?? [])
                ->withBreadcrumbs($this->breadcrumbs($service))
                ->toArray(),
            'serviceReviews' => $serviceReviews,
            'manualServicePage' => [
                'common' => trans('store.manual_services.common'),
                'relatedServices' => $suggestions->forManualService($request, $service),
                'relatedTranslations' => [
                    'eyebrow' => trans('store.services_section.eyebrow'),
                    'title' => trans('store.services_section.title'),
                    'open' => trans('store.product.sbc.related_link'),
                    'sbc' => [
                        'included' => trans('store.product.sbc.included_compact'),
                        'platform_prices' => trans('store.product.sbc.platform_prices'),
                        'unavailable_price' => trans('store.product.unavailable_price'),
                    ],
                ],
                'service' => trans("store.manual_services.{$service->value}"),
            ],
            'manualService' => [
                'service' => $service->value,
                'active' => $active,
                'scheduleVersion' => $schedule?->version,
                'addUrl' => $this->manualServiceCartUrl($request, $service),
                'platforms' => [Platform::PlayStation->value, Platform::Pc->value],
                'variantIds' => $this->variantIds($product),
                'tutorials' => [
                    'ea' => StoreTutorials::EA,
                    'playstation' => StoreTutorials::PLAYSTATION,
                ],
                'product' => $productPayload,
                'pricing' => $pricingPayload,
            ],
        ]);
    }

    /**
     * The Home → service trail, on the page's own locale canonicals.
     *
     * @return list<array{name: string, url: string}>
     */
    private function breadcrumbs(ServiceType $service): array
    {
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $home = StoreCanonicalUrls::alternatesFor('home')[$locale];
        $page = StoreCanonicalUrls::alternatesFor("store.{$service->value}")[$locale];

        return [
            ['name' => (string) trans('ui.home_title'), 'url' => $home],
            ['name' => (string) trans("store.manual_services.{$service->value}.title"), 'url' => $page],
        ];
    }

    private function displayConverter(
        Request $request,
        ConvertDisplayMoney $convertDisplayMoney,
    ): ?PreparedDisplayMoneyConverter {
        try {
            return $convertDisplayMoney->prepare(
                (string) $request->session()->get('display_currency'),
            );
        } catch (DomainException) {
            return null;
        }
    }

    /** @return array{0: ServicePriceSchedule|null, 1: FutChampionsPricing|RivalsPricing|null} */
    private function pricing(ServiceType $service, ReadManualServicePricing $reader): array
    {
        try {
            $result = $service === ServiceType::FutChampions
                ? $reader->futChampions()
                : $reader->rivals();

            return [$result['schedule'], $result['pricing']];
        } catch (DomainException) {
            return [
                ServicePriceSchedule::query()->where('service_type', $service)->first(),
                null,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function publicPricing(
        FutChampionsPricing|RivalsPricing $pricing,
        PreparedDisplayMoneyConverter $converter,
    ): array {
        if ($pricing instanceof FutChampionsPricing) {
            return [
                'currency' => $converter->currency,
                'rankOptions' => array_map(fn (int $rank): array => [
                    'rank' => $rank,
                    'price' => $this->money($pricing->priceForRank($rank, false), $converter),
                ], range(1, 6)),
                'urgentSurcharge' => $this->money($pricing->urgentSurcharge(), $converter),
            ];
        }

        $ladder = ['7', '6', '5', '4', '3', '2', '1', 'elite'];
        $steps = [];

        foreach (array_slice($ladder, 0, -1) as $index => $from) {
            $to = $ladder[$index + 1];
            $steps[] = [
                'from' => $from,
                'to' => $to,
                'price' => $this->money($pricing->priceForRoute($from, $to), $converter),
            ];
        }

        return [
            'currency' => $converter->currency,
            'ladder' => $ladder,
            'stepOptions' => $steps,
            'weeklyMatches' => $pricing->offersWeeklyMatches() ? [
                'includedWins' => $pricing->weeklyMatchesIncludedWins(),
                'price' => $this->money($pricing->weeklyMatchesPriceHalalah(), $converter),
            ] : null,
        ];
    }

    /** @return array{amountMinor: int, currency: string} */
    private function money(int $amountMinor, PreparedDisplayMoneyConverter $converter): array
    {
        return $converter->convert(Money::fromHalalah($amountMinor));
    }

    /** @return array<string, mixed> */
    private function product(?Product $product, ServiceType $service, string $slug): array
    {
        $locale = app()->getLocale();

        if (! $product instanceof Product) {
            return [
                'id' => null,
                'slug' => $slug,
                'name' => trans("store.manual_services.{$service->value}.title"),
                'description' => trans("store.manual_services.{$service->value}.intro"),
                'image' => [
                    'url' => "/images/store/services/{$this->imageName($service)}.webp",
                    'alt' => trans("store.manual_services.{$service->value}.title"),
                ],
            ];
        }

        $media = $product->media->first();

        return [
            'id' => $product->public_id,
            'slug' => $slug,
            'name' => $product->{"name_{$locale}"},
            'description' => $product->{"description_{$locale}"},
            'image' => [
                'url' => $media instanceof ProductMedia
                    ? Storage::disk($media->disk)->url($media->path)
                    : "/images/store/services/{$this->imageName($service)}.webp",
                'alt' => $media instanceof ProductMedia
                    ? ($media->{"alt_{$locale}"} ?: $product->{"name_{$locale}"})
                    : trans("store.manual_services.{$service->value}.title"),
            ],
        ];
    }

    /** @return array{slug: string, skus: list<string>} */
    private function identity(ServiceType $service): array
    {
        return match ($service) {
            ServiceType::FutChampions => [
                'slug' => 'fut-champions',
                'skus' => ['MANUAL_FUT_CHAMPIONS_PLAYSTATION', 'MANUAL_FUT_CHAMPIONS_PC'],
            ],
            ServiceType::Rivals => [
                'slug' => 'division-rivals',
                'skus' => ['MANUAL_RIVALS_PLAYSTATION', 'MANUAL_RIVALS_PC'],
            ],
            default => throw new DomainException('Unsupported manual service.'),
        };
    }

    private function imageName(ServiceType $service): string
    {
        return $service === ServiceType::FutChampions ? 'fut-champions' : 'rivals';
    }

    private function manualServiceCartUrl(Request $request, ServiceType $service): string
    {
        $name = $service === ServiceType::FutChampions
            ? 'cart.items.fut-champions.store'
            : 'cart.items.rivals.store';

        return $this->route($request, $name);
    }

    /**
     * The public variant ids keyed by platform, for the up-front in-cart
     * state. Only active variants count; anything else stays null so the
     * page never claims a line is in the cart that cannot be bought.
     *
     * @return array{playstation: string|null, pc: string|null}
     */
    private function variantIds(?Product $product): array
    {
        $ids = ['playstation' => null, 'pc' => null];

        if (! $product instanceof Product) {
            return $ids;
        }

        foreach ($product->variants as $variant) {
            $platform = $variant->platform->value;

            if (array_key_exists($platform, $ids)
                && $variant->is_active
                && $variant->public_id !== '') {
                $ids[$platform] = $variant->public_id;
            }
        }

        return $ids;
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

        return $this->route($request, 'cart.items.credentials.show', ['cartItem' => $item->public_id]);
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
