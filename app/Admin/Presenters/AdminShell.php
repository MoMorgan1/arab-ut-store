<?php

namespace App\Admin\Presenters;

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Support\Facades\Route;

final class AdminShell
{
    /**
     * @return array{
     *     adminIdentity: array{name: string, role: string},
     *     adminNavigation: list<array{key: string, label: string, url: string, children?: list<array{key: string, label: string, url: string}>}>,
     *     permissions: list<string>,
     *     logoutUrl: string
     * }
     */
    public function for(User $actor, string $locale): array
    {
        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $navigation = [
            [
                'key' => 'overview',
                'label' => (string) trans('admin.navigation.overview', locale: $locale),
                'url' => route($prefix.'overview', absolute: false),
            ],
        ];

        if ($actor->can(AdminPermission::OrdersView->value)) {
            $navigation[] = [
                'key' => 'orders',
                'label' => (string) trans('admin.navigation.orders', locale: $locale),
                'url' => route($prefix.'orders', absolute: false),
            ];
        }

        if ($actor->can(AdminPermission::CustomersView->value)) {
            $navigation[] = [
                'key' => 'customers',
                'label' => (string) trans('admin.navigation.customers', locale: $locale),
                'url' => route($prefix.'customers', absolute: false),
            ];
        }

        if ($actor->can(AdminPermission::ChatView->value)) {
            $navigation[] = [
                'key' => 'conversations',
                'label' => (string) trans('admin.navigation.conversations', locale: $locale),
                'url' => route($prefix.'conversations', absolute: false),
            ];
        }

        $catalogChildren = [];
        if ($actor->can(AdminPermission::CatalogView->value)) {
            $catalogChildren[] = [
                'key' => 'products',
                'label' => (string) trans('admin.navigation.products', locale: $locale),
                'url' => route($prefix.'products', absolute: false),
            ];
            $catalogChildren[] = [
                'key' => 'categories',
                'label' => (string) trans('admin.navigation.categories', locale: $locale),
                'url' => Route::has($prefix.'categories')
                    ? route($prefix.'categories', absolute: false)
                    : ($prefix === 'localized.admin.' ? '/en/admin/categories' : '/admin/categories'),
            ];
        }

        if ($catalogChildren !== []) {
            $navigation[] = [
                'key' => 'catalog',
                'label' => (string) trans('admin.navigation.catalog', locale: $locale),
                'url' => $catalogChildren[0]['url'],
                'children' => $catalogChildren,
            ];
        }

        $marketingChildren = [];
        if ($actor->can(AdminPermission::MarketingView->value)) {
            $marketingChildren[] = [
                'key' => 'marketingCoupons',
                'label' => (string) trans('admin.navigation.marketingCoupons', locale: $locale),
                'url' => route($prefix.'marketing.coupons', absolute: false),
            ];
            $marketingChildren[] = [
                'key' => 'marketingPromotions',
                'label' => (string) trans('admin.navigation.marketingPromotions', locale: $locale),
                'url' => route($prefix.'marketing.promotions', absolute: false),
            ];
            $marketingChildren[] = [
                'key' => 'marketingReviews',
                'label' => (string) trans('admin.navigation.marketingReviews', locale: $locale),
                'url' => route($prefix.'reviews', absolute: false),
            ];
        }

        if ($actor->can(AdminPermission::LoyaltyView->value)) {
            $marketingChildren[] = [
                'key' => 'marketingLoyalty',
                'label' => (string) trans('admin.navigation.marketingLoyalty', locale: $locale),
                'url' => route($prefix.'marketing.loyalty', absolute: false),
            ];
        }

        if ($actor->can(AdminPermission::MarketingView->value)) {
            $marketingChildren[] = [
                'key' => 'marketingFaq',
                'label' => (string) trans('admin.navigation.marketingFaq', locale: $locale),
                'url' => route($prefix.'marketing.faq', absolute: false),
            ];
        }

        if ($marketingChildren !== []) {
            $navigation[] = [
                'key' => 'marketing',
                'label' => (string) trans('admin.navigation.marketing', locale: $locale),
                'url' => $marketingChildren[0]['url'],
                'children' => $marketingChildren,
            ];
        }

        $navigation[] = [
            'key' => 'settings',
            'label' => (string) trans('admin.navigation.settings', locale: $locale),
            'url' => route($prefix.'settings', absolute: false),
        ];

        $navigation[] = [
            'key' => 'more',
            'label' => (string) trans('admin.navigation.more', locale: $locale),
            'url' => route($prefix.'more', absolute: false),
        ];

        return [
            'adminIdentity' => [
                'name' => $actor->name,
                'role' => $actor->role->value,
            ],
            'adminNavigation' => $navigation,
            'permissions' => array_values(array_map(
                fn (AdminPermission $permission): string => $permission->value,
                array_filter(
                    AdminPermission::cases(),
                    fn (AdminPermission $permission): bool => $actor->can($permission->value),
                ),
            )),
            'logoutUrl' => route('logout', absolute: false),
        ];
    }

    /**
     * @return array{
     *     adminIdentity: array{name: string, role: string},
     *     adminNavigation: list<array{key: string, label: string, url: string, children?: list<array{key: string, label: string, url: string}>}>,
     *     permissions: list<string>,
     *     logoutUrl: string
     * }
     */
    public function compose(User $actor, string $locale): array
    {
        return $this->for($actor, $locale);
    }
}
