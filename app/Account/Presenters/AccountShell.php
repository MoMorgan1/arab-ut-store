<?php

namespace App\Account\Presenters;

use App\Models\User;

final class AccountShell
{
    /** @return array<string, mixed> */
    public function for(User $user, string $locale): array
    {
        $localized = $locale === 'en';

        return [
            'accountUi' => trans('account'),
            'accountIdentity' => [
                'name' => $user->name,
                'greeting' => trans('account.greeting', ['name' => $user->name]),
            ],
            'accountNavigation' => [
                [
                    'key' => 'overview',
                    'label' => trans('account.navigation.overview'),
                    'url' => route(
                        $localized ? 'localized.account.overview' : 'account.overview',
                        absolute: false,
                    ),
                ],
                [
                    'key' => 'orders',
                    'label' => trans('account.navigation.orders'),
                    'url' => route(
                        $localized ? 'localized.account.orders' : 'account.orders',
                        absolute: false,
                    ),
                ],
                [
                    'key' => 'wallet',
                    'label' => trans('account.navigation.wallet'),
                    'url' => route(
                        $localized ? 'localized.account.wallet' : 'account.wallet',
                        absolute: false,
                    ),
                ],
                [
                    'key' => 'profile',
                    'label' => trans('account.navigation.profile'),
                    'url' => route(
                        $localized ? 'localized.account.profile.show' : 'account.profile.show',
                        absolute: false,
                    ),
                ],
            ],
            'logoutUrl' => route('logout', absolute: false),
        ];
    }
}
