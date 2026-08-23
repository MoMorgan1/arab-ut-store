<?php

namespace App\Admin\Presenters;

use App\Enums\AdminPermission;
use App\Models\User;

final class AdminShell
{
    /**
     * @return array{
     *     adminIdentity: array{name: string, role: string},
     *     adminNavigation: list<array{key: string, label: string, url: string}>,
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

        if ($actor->can(AdminPermission::CatalogView->value)) {
            $navigation[] = [
                'key' => 'products',
                'label' => (string) trans('admin.navigation.products', locale: $locale),
                'url' => route($prefix.'products', absolute: false),
            ];
        }

        $navigation[] = [
            'key' => 'settings',
            'label' => (string) trans('admin.navigation.settings', locale: $locale),
            'url' => route($prefix.'settings', absolute: false),
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
}
