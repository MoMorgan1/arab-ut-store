<?php

namespace App\Http\Controllers\Store;

use App\Actions\Catalog\StoreCatalogReader;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CatalogProductController extends Controller
{
    public function __invoke(Request $request, StoreCatalogReader $catalog): Response
    {
        return Inertia::render('store/catalog-product', [
            'catalogCartUrl' => $this->route($request, 'cart.items.catalog.store'),
            'sbcCartUrl' => $this->route($request, 'cart.items.sbc.store'),
            'backUrl' => $this->route($request, 'home').'#services',
            'productPage' => trans('store.product'),
            'catalog' => $catalog->featuredProduct(
                ServiceType::from((string) $request->route('service')),
                app()->getLocale(),
                (string) ($request->session()->get('display_currency') ?? config('store.default_display_currency')),
            ),
        ]);
    }

    private function route(Request $request, string $name): string
    {
        $localized = $request->route('locale') === 'en';

        return route($localized ? "localized.{$name}" : $name, $localized ? ['locale' => 'en'] : [], absolute: false);
    }
}
