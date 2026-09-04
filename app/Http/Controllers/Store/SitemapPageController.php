<?php

namespace App\Http\Controllers\Store;

use App\Actions\Catalog\StoreCatalogReader;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Support\Seo\StorePageSeo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SitemapPageController extends Controller
{
    public function __invoke(Request $request, StoreCatalogReader $catalog): Response
    {
        $locale = app()->getLocale();

        return Inertia::render('store/sitemap', [
            'sitemapPage' => [
                'eyebrow' => trans('store.sitemap_page.eyebrow'),
                'title' => trans('store.sitemap_page.title'),
                'groups' => $this->groups($request, $catalog, $locale),
            ],
            'seo' => StorePageSeo::default(trans('store.sitemap_page.title'))->toArray(),
        ]);
    }

    /**
     * @return list<array{heading: string, links: list<array{label: string, href: string}>}>
     */
    private function groups(Request $request, StoreCatalogReader $catalog, string $locale): array
    {
        $services = [
            ['label' => (string) trans('store.coins_section.tag'), 'href' => $this->route($request, 'home')],
            ['label' => (string) trans('store.services.sbc.title'), 'href' => $this->route($request, 'store.sbc')],
            ['label' => (string) trans('store.services.objectives.title'), 'href' => $this->route($request, 'store.objectives')],
            ['label' => (string) trans('store.services.rivals.title'), 'href' => $this->route($request, 'store.rivals')],
            ['label' => (string) trans('store.services.fut_champions.title'), 'href' => $this->route($request, 'store.fut_champions')],
        ];

        foreach ([ServiceType::Sbc, ServiceType::Objectives] as $service) {
            foreach ($catalog->publicProductLinks($service, $locale) as $product) {
                $services[] = [
                    'label' => $product['name'],
                    'href' => $this->route($request, "store.{$service->value}.show", ['slug' => $product['slug']]),
                ];
            }
        }

        return [
            [
                'heading' => (string) trans('store.sitemap_page.groups.services'),
                'links' => $services,
            ],
            [
                'heading' => (string) trans('store.sitemap_page.groups.store'),
                'links' => [
                    ['label' => (string) trans('store.reviews.title'), 'href' => $this->route($request, 'store.reviews')],
                    ['label' => (string) trans('store.sitemap_page.title'), 'href' => $this->route($request, 'store.sitemap-page')],
                ],
            ],
            [
                'heading' => (string) trans('store.sitemap_page.groups.policies'),
                'links' => [
                    ['label' => (string) trans('ui.footer.privacy'), 'href' => $this->route($request, 'store.privacy')],
                    ['label' => (string) trans('ui.footer.returns'), 'href' => $this->route($request, 'store.returns')],
                    ['label' => (string) trans('ui.footer.warranty'), 'href' => $this->route($request, 'store.warranty')],
                    ['label' => (string) trans('ui.footer.ea_backup_codes'), 'href' => $this->route($request, 'store.ea_backup_codes')],
                    ['label' => (string) trans('ui.footer.terms'), 'href' => $this->route($request, 'store.terms')],
                ],
            ],
        ];
    }

    /** @param array<string, string> $parameters */
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
