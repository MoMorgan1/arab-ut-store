<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminPassword
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! is_string($user->password) || $user->password === '') {
            return redirect()->to(route(
                $request->route('locale') === 'en'
                    ? 'localized.account.security.show'
                    : 'account.security.show',
                absolute: false,
            ));
        }

        return $next($request);
    }
}
