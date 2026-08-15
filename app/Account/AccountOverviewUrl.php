<?php

namespace App\Account;

use App\Models\User;

final class AccountOverviewUrl
{
    public function for(User $user): string
    {
        return $user->preferred_locale === 'en'
            ? route('localized.account.overview', absolute: false)
            : route('account.overview', absolute: false);
    }
}
