<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SupportController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = app()->getLocale();
        $params = [];
        $order = $request->query('order');

        if (is_string($order) && $order !== '') {
            $params['order'] = $order;
        }

        $routeName = $locale === 'en' ? 'localized.account.profile.show' : 'account.profile.show';
        $url = route($routeName, $params, absolute: false).'#support';

        return redirect()->to($url, 302);
    }
}
