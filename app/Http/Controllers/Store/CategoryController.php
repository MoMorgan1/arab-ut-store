<?php

namespace App\Http\Controllers\Store;

use App\Actions\Catalog\StoreCatalogReader;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CategoryController extends Controller
{
    public function __invoke(Request $request, StoreCatalogReader $catalog): Response
    {
        $service = ServiceType::from((string) $request->route('service'));
        $filters = $service === ServiceType::Sbc
            ? ['all', 'players', 'icons', 'upgrades', 'foundations']
            : ['all'];
        $input = Validator::make($request->query(), [
            'filter' => ['sometimes', 'string', Rule::in($filters)],
            'sort' => ['sometimes', 'string', Rule::in(['recommended', 'newest', 'price_asc', 'price_desc'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        return Inertia::render('store/category', [
            'catalogCartUrl' => $this->route($request, 'cart.items.catalog.store'),
            'catalogPageUrl' => $this->route($request, "store.{$service->value}"),
            'catalogPage' => trans('store.catalog'),
            'servicePage' => trans("store.services.{$service->value}"),
            'catalog' => $catalog->category(
                $service,
                app()->getLocale(),
                (string) ($request->session()->get('display_currency') ?? config('store.default_display_currency')),
                (string) ($input['filter'] ?? 'all'),
                (string) ($input['sort'] ?? 'recommended'),
                (string) ($input['q'] ?? ''),
                (int) ($input['page'] ?? 1),
            ),
        ]);
    }

    private function route(Request $request, string $name): string
    {
        $localized = $request->route('locale') === 'en';

        return route($localized ? "localized.{$name}" : $name, $localized ? ['locale' => 'en'] : [], absolute: false);
    }
}
