<?php

namespace App\Http\Middleware;

use App\Enums\AdminPermission;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminAccess
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->can(AdminPermission::DashboardView->value),
            403,
        );

        return $next($request);
    }
}
