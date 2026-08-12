<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class VerifyN8nCatalogSignature
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
        $configuredKey = Config::get($this->keyConfigPath());
        $configuredSecret = Config::get($this->secretConfigPath());

        if (! is_string($configuredKey)
            || $configuredKey === ''
            || ! is_string($configuredSecret)
            || $configuredSecret === ''
            || ! hash_equals($configuredKey, $providedKey)
        ) {
            return false;
        }

        return hash_equals($this->expectedSignature($request, $configuredSecret), $providedSignature);
    }

    private function expectedSignature(Request $request, string $secret): string
    {
        $signedPayload = (string) $request->header('X-ArabUT-Timestamp')."\n"
            .(string) $request->header('X-ArabUT-Event')."\n";

        $signatureScope = $this->signatureScope();

        if ($signatureScope !== null) {
            $signedPayload .= $signatureScope."\n";
        }

        $signedPayload .= $request->getContent();

        return hash_hmac('sha256', $signedPayload, $secret);
    }

    protected function keyConfigPath(): string
    {
        return 'services.n8n.catalog_key';
    }

    protected function secretConfigPath(): string
    {
        return 'services.n8n.catalog_secret';
    }

    protected function signatureScope(): ?string
    {
        return null;
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
            'error' => ['code' => 'invalid_signature', 'message' => 'The request signature is invalid.'],
        ], 401)->header('Cache-Control', 'no-store');
    }

    private function stale(): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'stale_snapshot', 'message' => 'The catalog snapshot is outside the freshness window.'],
        ], 409)->header('Cache-Control', 'no-store');
    }
}
