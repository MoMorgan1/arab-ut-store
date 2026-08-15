<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

final class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        Inertia::clearHistory();

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect(Fortify::redirects('logout', '/'));
    }
}
