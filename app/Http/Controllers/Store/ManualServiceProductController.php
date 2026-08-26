<?php

namespace App\Http\Controllers\Store;

use App\Actions\Pricing\ConvertDisplayMoney;
use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\Platform;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ServicePriceSchedule;
use App\Support\Money;
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
    private const EA_TUTORIAL = 'https://youtube.com/shorts/hNIW1ps_t3k?si=i9MR5izDKRhpRNjo';

    private const PLAYSTATION_TUTORIAL = 'https://youtu.be/fCAKsusuHR8?si=cYzL6fwszL4ExwPK';

    public function __invoke(
        Request $request,
        ReadManualServicePricing $readPricing,
        ConvertDisplayMoney $convertDisplayMoney,
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

        return Inertia::render('store/manual-service', [
            'backUrl' => $this->route($request, 'home').'#services',
            'manualServicePage' => [
                'common' => trans('store.manual_services.common'),
                'relatedServices' => $this->relatedServices($request, $service),
                'relatedTranslations' => [
                    'eyebrow' => trans('store.services_section.eyebrow'),
                    'title' => trans('store.services_section.title'),
                    'open' => trans('store.product.sbc.related_link'),
                ],
                'service' => trans("store.manual_services.{$service->value}"),
            ],
            'manualService' => [
                'service' => $service->value,
                'active' => $active,
                'scheduleVersion' => $schedule?->version,
                'addUrl' => $this->manualServiceCartUrl($request, $service),
                'platforms' => [Platform::PlayStation->value, Platform::Pc->value],
                'tutorials' => [
                    'ea' => self::EA_TUTORIAL,
                    'playstation' => self::PLAYSTATION_TUTORIAL,
                ],
                'product' => $this->product($product, $service, $identity['slug']),
                'pricing' => $pricingPayload,
            ],
        ]);
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

    /** @return list<array{key: string, title: string, description: string, href: string, imageUrl: string}> */
    private function relatedServices(Request $request, ServiceType $service): array
    {
        $related = [
            ['sbc', 'store.sbc', '/images/store/services/sbc.webp'],
            $service === ServiceType::FutChampions
                ? ['rivals', 'store.rivals', '/images/store/services/rivals.webp']
                : ['fut_champions', 'store.fut_champions', '/images/store/services/fut-champions.webp'],
        ];

        return array_map(fn (array $serviceCard): array => [
            'key' => $serviceCard[0],
            'title' => trans("store.services.{$serviceCard[0]}.title"),
            'description' => trans("store.services.{$serviceCard[0]}.card_description"),
            'href' => $this->route($request, $serviceCard[1]),
            'imageUrl' => $serviceCard[2],
        ], $related);
    }

    private function route(Request $request, string $name): string
    {
        $localized = $request->route('locale') === 'en';

        return route($localized ? "localized.{$name}" : $name, $localized ? ['locale' => 'en'] : [], absolute: false);
    }
}
