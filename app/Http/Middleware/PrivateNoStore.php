<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PrivateNoStore
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $downstreamResponse = $next($request);
        $downstreamResponse->headers->set('Cache-Control', 'no-store, private');

        return $downstreamResponse;
    }
}
