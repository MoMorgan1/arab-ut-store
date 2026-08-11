<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireCatalogCartJson
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isJson()) {
            return new JsonResponse([
                'error' => [
                    'code' => 'unsupported_media_type',
                    'message' => trans('store.cart.catalog_json_required'),
                ],
            ], 415, ['Cache-Control' => 'no-store']);
        }

        return $next($request);
    }
}
