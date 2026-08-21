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
        return [
            'adminIdentity' => [
                'name' => $actor->name,
                'role' => $actor->role->value,
            ],
            'adminNavigation' => [
                [
                    'key' => 'overview',
                    'label' => (string) trans('admin.navigation.overview', locale: $locale),
                    'url' => route('admin.overview', absolute: false),
                ],
                [
                    'key' => 'security',
                    'label' => (string) trans('admin.navigation.security', locale: $locale),
                    'url' => route('admin.security.mfa', absolute: false),
                ],
            ],
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
