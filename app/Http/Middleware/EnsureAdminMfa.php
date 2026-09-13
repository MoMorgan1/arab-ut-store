<?php

namespace App\Http\Middleware;

use App\Auth\AdminMfaSession;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminMfa
{
    public function __construct(private readonly AdminMfaSession $mfaSession) {}

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

        if ($request->hasSession() && ! $this->mfaSession->satisfied($user, $request)) {
            return redirect()->guest(route($prefix.'confirm-2fa', absolute: false));
        }

        return $next($request);
    }
}
