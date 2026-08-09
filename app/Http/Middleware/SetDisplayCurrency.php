<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetDisplayCurrency
{
    /**
     * Persist a supported display currency while checkout remains in SAR.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $currency = $request->query('currency');
        $supportedCurrencies = config('store.display_currencies');

        if (in_array($currency, $supportedCurrencies, true)) {
            $request->session()->put('display_currency', $currency);
        }

        if (! $request->session()->has('display_currency')) {
            $request->session()->put('display_currency', config('store.default_display_currency'));
        }

        return $next($request);
    }
}
