<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyPaylinkWebhook
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = trim((string) config('services.paylink.webhook_token'));
        $provided = $request->bearerToken();

        if (strlen($configured) < 32 || ! is_string($provided) || ! hash_equals($configured, $provided)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'invalid_webhook_authorization',
                    'message' => 'The webhook authorization is invalid.',
                ],
            ], 401);
        }

        return $next($request);
    }
}
