<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Set the application locale from the explicit public route prefix.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');
        $supportedLocales = config('store.locales');

        if (! in_array($locale, $supportedLocales, true)) {
            $locale = config('store.default_locale');
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
