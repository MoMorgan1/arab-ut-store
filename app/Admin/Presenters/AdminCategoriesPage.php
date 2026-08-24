<?php

namespace App\Admin\Presenters;

use App\Admin\Queries\ListAdminCategories;
use App\Models\CatalogSource;
use App\Models\User;

final readonly class AdminCategoriesPage
{
    public function __construct(
        private AdminShell $shell,
        private ListAdminCategories $categoriesQuery,
    ) {}

    /**
     * @param array{
     *     search?: ?string,
     *     visibility?: 'visible'|'admin_hidden'|'automation_hidden'|null,
     *     source?: ?string,
     *     sort?: string,
     *     direction?: string,
     *     per_page?: int,
     *     page?: int
     * } $filters
     * @return array<string, mixed>
     */
    public function for(User $actor, string $locale, array $filters): array
    {
        $categoryData = $this->categoriesQuery->paginate($filters, $locale);

        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $sources = [
            [
                'value' => 'manual',
                'label' => (string) trans('admin.categories.sourceManual', locale: $locale),
            ],
        ];

        foreach (CatalogSource::query()->select(['key', 'name'])->orderBy('name')->get() as $catalogSource) {
            $sources[] = [
                'value' => (string) $catalogSource->key,
                'label' => (string) $catalogSource->name,
            ];
        }

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'categories' => $categoryData['categories'],
            'pagination' => $categoryData['pagination'],
            'filters' => $filters,
            'filterOptions' => [
                'visibilities' => [
                    [
                        'value' => 'visible',
                        'label' => (string) trans('admin.categories.visibilityVisible', locale: $locale),
                    ],
                    [
                        'value' => 'admin_hidden',
                        'label' => (string) trans('admin.categories.visibilityAdminHidden', locale: $locale),
                    ],
                    [
                        'value' => 'automation_hidden',
                        'label' => (string) trans('admin.categories.visibilityAutomationHidden', locale: $locale),
                    ],
                ],
                'sources' => $sources,
                'perPageOptions' => [15, 25, 50, 100],
            ],
            'productsUrl' => route($prefix.'products', absolute: false),
            'visibilityUrlTemplate' => route($prefix.'categories.visibility.store', ['publicId' => '__ID__'], absolute: false),
        ];
    }
}
