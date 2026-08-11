<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

final class VerifyN8nPricingSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->signatureMatches($request)) {
            return $this->unauthorized();
        }

        if (! $this->timestampIsFresh($request)) {
            return $this->stale();
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function signatureMatches(Request $request): bool
    {
        $providedKey = (string) $request->header('X-ArabUT-Key');
        $providedSignature = (string) $request->header('X-ArabUT-Signature');
        $configuredKey = Config::get('services.n8n.pricing_key');
        $configuredSecret = Config::get('services.n8n.pricing_secret');

        if (! is_string($configuredKey)
            || $configuredKey === ''
            || ! is_string($configuredSecret)
            || $configuredSecret === ''
            || ! hash_equals($configuredKey, $providedKey)) {
            return false;
        }

        $signedPayload = (string) $request->header('X-ArabUT-Timestamp')."\n"
            .(string) $request->header('X-ArabUT-Event')."\n"
            .$request->getContent();

        return hash_equals(
            hash_hmac('sha256', $signedPayload, $configuredSecret),
            $providedSignature,
        );
    }

    private function timestampIsFresh(Request $request): bool
    {
        $timestamp = (string) $request->header('X-ArabUT-Timestamp');

        return strlen($timestamp) === 10
            && ctype_digit($timestamp)
            && abs(now()->getTimestamp() - (int) $timestamp) <= 300;
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'invalid_signature', 'message' => 'The pricing request signature is invalid.'],
        ], 401)->header('Cache-Control', 'no-store');
    }

    private function stale(): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'stale_pricing_run', 'message' => 'The pricing request is outside the freshness window.'],
        ], 409)->header('Cache-Control', 'no-store');
    }
}
