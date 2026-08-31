<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminMfa
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $isLocalized = str_starts_with((string) $request->route()?->getName(), 'localized.admin.');
        $prefix = $isLocalized ? 'localized.admin.' : 'admin.';

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->to(route($prefix.'settings', absolute: false));
        }

        if ($request->hasSession() && ! $request->session()->has('auth.two_factor_confirmed_at')) {
            return redirect()->guest(route($prefix.'confirm-2fa', absolute: false));
        }

        return $next($request);
    }
}
