<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * The baseline read is signed with the pricing secret, and with that alone.
 *
 * The publish route also checks `X-ArabUT-Key`, which n8n holds in an HTTP
 * credential; only the HTTP Request node can send it. This route is called
 * from a Code node, which can read `$env.N8N_PRICING_SECRET` and nothing else,
 * so the signature is the whole credential here. It carries the method and the
 * path, so a signature minted for one route cannot be replayed against
 * another, and the route is read-only.
 */
final class VerifyN8nPricingBaselineSignature
{
    private const PATH = '/api/automation/v1/pricing/coins/baseline';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->method() !== 'GET') {
            return $this->error(
                405,
                'invalid_baseline_read_method',
                'The Coins pricing baseline endpoint accepts GET only.',
            )->header('Allow', 'GET');
        }

        if (! $this->signatureMatches($request)) {
            return $this->error(
                401,
                'invalid_signature',
                'The Coins pricing baseline signature is invalid.',
            );
        }

        if (! $this->timestampIsFresh($request)) {
            return $this->error(
                409,
                'stale_baseline_read',
                'The Coins pricing baseline request is outside the freshness window.',
            );
        }

        if ($request->query->count() !== 0 || $request->getContent() !== '') {
            return $this->error(
                422,
                'invalid_baseline_read',
                'The Coins pricing baseline request cannot contain query or body input.',
            );
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function signatureMatches(Request $request): bool
    {
        $secret = Config::get('services.n8n.pricing_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $signedPayload = (string) $request->header('X-ArabUT-Timestamp')."\nGET\n".self::PATH."\n";

        return hash_equals(
            hash_hmac('sha256', $signedPayload, $secret),
            (string) $request->header('X-ArabUT-Signature'),
        );
    }

    private function timestampIsFresh(Request $request): bool
    {
        $timestamp = (string) $request->header('X-ArabUT-Timestamp');

        return strlen($timestamp) === 10
            && ctype_digit($timestamp)
            && abs(now()->getTimestamp() - (int) $timestamp) <= 300;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], $status)->header('Cache-Control', 'no-store');
    }
}
