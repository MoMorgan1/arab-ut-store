<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEligibleTwoFactorChallenge
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('two-factor.login', 'two-factor.login.store')) {
            return $next($request);
        }

        $challengedUserId = $request->session()->get('login.id');
        $challengedUser = is_int($challengedUserId)
            ? User::query()->find($challengedUserId)
            : null;

        $locale = $challengedUser?->preferred_locale === 'en' ? 'en' : 'ar';
        app()->setLocale($locale);

        if (! $this->isEligible($challengedUser)) {
            $request->session()->forget(['login.id', 'login.remember']);

            return redirect($this->loginUrl($locale))->withErrors([
                'email' => trans('auth_ui.two_factor_challenge.invalid_code'),
            ]);
        }

        return $next($request);
    }

    private function isEligible(?User $user): bool
    {
        return $user instanceof User
            && $user->is_active
            && $user->password !== null
            && $user->hasEnabledTwoFactorAuthentication();
    }

    private function loginUrl(string $locale): string
    {
        return $locale === 'en'
            ? route('localized.login', ['locale' => 'en'])
            : route('login');
    }
}
