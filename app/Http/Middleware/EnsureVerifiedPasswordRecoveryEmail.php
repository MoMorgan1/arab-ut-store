<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

final class EnsureVerifiedPasswordRecoveryEmail
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs('password.email', 'localized.password.email')) {
            return $next($request);
        }

        $email = mb_strtolower(trim((string) $request->input(Fortify::email())));
        $eligible = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNotNull('email_verified_at')
            ->where('is_active', true)
            ->exists();

        if ($eligible) {
            return $next($request);
        }

        $responsable = app(SuccessfulPasswordResetLinkRequestResponse::class, [
            'status' => Password::RESET_LINK_SENT,
        ]);

        return $responsable->toResponse($request);
    }
}
