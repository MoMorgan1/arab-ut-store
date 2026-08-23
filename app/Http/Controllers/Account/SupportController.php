<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

final class SupportController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $locale = app()->getLocale();
        $routeName = $locale === 'en' ? 'localized.account.overview' : 'account.overview';

        return redirect()->to(route($routeName, absolute: false), 302);
    }
}
