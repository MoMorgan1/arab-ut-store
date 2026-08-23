<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ListAdminProducts;
use App\Enums\ServiceType;
use App\Models\CatalogSource;
use App\Models\User;

final readonly class AdminProductsPage
{
    public function __construct(
        private AdminShell $shell,
        private ListAdminProducts $productsQuery,
    ) {}

    /**
     * @param array{
     *     search?: ?string,
     *     service_type?: ?string,
     *     authority?: 'manual'|'automation'|null,
     *     source?: ?string,
     *     visibility?: 'visible'|'hidden'|null,
     *     archived?: 'active'|'archived'|null,
     *     sort?: string,
     *     direction?: string,
     *     per_page?: int,
     *     page?: int
     * } $filters
     * @return array<string, mixed>
     */
    public function for(User $actor, string $locale, array $filters): array
    {
        $productData = $this->productsQuery->paginate($filters, $locale);

        $sources = [
            [
                'value' => 'manual',
                'label' => (string) trans('admin.products.sourceManual', locale: $locale),
            ],
        ];

        foreach (CatalogSource::query()->select(['key', 'name'])->orderBy('name')->get() as $catalogSource) {
            $sources[] = [
                'value' => (string) $catalogSource->key,
                'label' => (string) $catalogSource->name,
            ];
        }

        $services = array_map(
            fn (ServiceType $type): array => [
                'value' => $type->value,
                'label' => (string) (trans('admin.orders.services.'.$type->value, locale: $locale) !== 'admin.orders.services.'.$type->value
                    ? trans('admin.orders.services.'.$type->value, locale: $locale)
                    : $type->value),
            ],
            ServiceType::cases(),
        );

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'products' => $productData['products'],
            'pagination' => $productData['pagination'],
            'filters' => $filters,
            'filterOptions' => [
                'services' => $services,
                'authorities' => [
                    [
                        'value' => 'manual',
                        'label' => (string) trans('admin.products.authorityManual', locale: $locale),
                    ],
                    [
                        'value' => 'automation',
                        'label' => (string) trans('admin.products.authorityAutomation', locale: $locale),
                    ],
                ],
                'sources' => $sources,
                'visibilities' => [
                    [
                        'value' => 'visible',
                        'label' => (string) trans('admin.products.visibilityVisible', locale: $locale),
                    ],
                    [
                        'value' => 'hidden',
                        'label' => (string) trans('admin.products.visibilityHidden', locale: $locale),
                    ],
                ],
                'archived' => [
                    [
                        'value' => 'active',
                        'label' => (string) trans('admin.products.archivedActive', locale: $locale),
                    ],
                    [
                        'value' => 'archived',
                        'label' => (string) trans('admin.products.archivedArchived', locale: $locale),
                    ],
                ],
                'perPageOptions' => [15, 25, 50, 100],
            ],
        ];
    }
}
