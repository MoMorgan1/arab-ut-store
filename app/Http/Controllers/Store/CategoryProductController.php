<?php

namespace App\Http\Controllers\Store;

use App\Actions\Catalog\StoreCatalogReader;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Services\Reviews\StoreReviewReader;
use App\Support\Seo\StorePageSeo;
use App\Support\StoreTutorials;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CategoryProductController extends Controller
{
    public function __invoke(
        Request $request,
        StoreCatalogReader $catalog,
        StoreReviewReader $reviewReader,
    ): Response {
        $service = ServiceType::from((string) $request->route('service'));
        $locale = app()->getLocale();
        $catalogPage = $catalog->productBySlug(
            $service,
            (string) $request->route('slug'),
            $locale,
            (string) ($request->session()->get('display_currency') ?? config('store.default_display_currency')),
        );

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

        return Inertia::render('store/catalog-product', [
            'catalogCartUrl' => $this->route($request, 'cart.items.catalog.store'),
            'sbcCartUrl' => $this->route($request, 'cart.items.sbc.store'),
            'backUrl' => $this->route($request, 'store.'.(string) $request->route('service')),
            'productPage' => trans('store.product'),
            'manualCommon' => trans('store.manual_services.common'),
            'tutorials' => [
                'ea' => StoreTutorials::EA,
            ],
            'catalog' => $catalogPage,
            'serviceReviews' => $serviceReviews,
            'seo' => StorePageSeo::fromCatalogProduct($catalogPage['product'])->toArray(),
        ]);
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
