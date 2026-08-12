<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

final class VerifyN8nSbcPricingReadSignature
{
    private const PATH = '/api/automation/v1/pricing/coins/sbc-bases';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->signatureMatches($request)) {
            return $this->error(
                401,
                'invalid_signature',
                'The SBC pricing read signature is invalid.',
            );
        }

        if (! $this->timestampIsFresh($request)) {
            return $this->error(
                409,
                'stale_sbc_pricing_read',
                'The SBC pricing read request is outside the freshness window.',
            );
        }

        if ($request->query->count() !== 0 || $request->getContent() !== '') {
            return $this->error(
                422,
                'invalid_sbc_pricing_read',
                'The SBC pricing read request cannot contain query or body input.',
            );
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function signatureMatches(Request $request): bool
    {
        $providedKey = (string) $request->header('X-ArabUT-Key');
        $providedSignature = (string) $request->header('X-ArabUT-Signature');
        $configuredKey = Config::get('services.n8n.sbc_pricing_read_key');
        $configuredSecret = Config::get('services.n8n.sbc_pricing_read_secret');

        if (! is_string($configuredKey)
            || $configuredKey === ''
            || ! is_string($configuredSecret)
            || $configuredSecret === ''
            || ! hash_equals($configuredKey, $providedKey)) {
            return false;
        }

        $timestamp = (string) $request->header('X-ArabUT-Timestamp');
        $signedPayload = $timestamp."\nGET\n".self::PATH."\n";

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

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], $status)->header('Cache-Control', 'no-store');
    }
}
