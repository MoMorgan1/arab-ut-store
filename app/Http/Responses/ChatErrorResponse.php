<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ChatErrorResponse
{
    public function render(Response $response, Throwable $exception, Request $request): Response
    {
        if (! $this->isChatRequest($request)) {
            return $response;
        }

        $status = $response->getStatusCode();

        if ($status === 409) {
            return $this->error('conversation_closed', 'conversation_closed', 409);
        }

        if ($status === 422) {
            return $this->error('validation_error', 'validation_error', 422);
        }

        if ($status === 429) {
            return $this->error('rate_limited', 'rate_limited', 429, $this->safeRateLimitHeaders($response));
        }

        if ($status >= 500) {
            return $this->error('chat_unavailable', 'unavailable', 500);
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function agentUnavailable(): JsonResponse
    {
        return $this->error('agent_unavailable', 'unavailable', 404);
    }

    /** @param array<string, list<string>> $headers */
    public function error(string $code, string $message, int $status, array $headers = []): JsonResponse
    {
        $resolvedMessage = str_starts_with($message, 'chat.')
            ? trans($message)
            : (trans("chat.{$message}") !== "chat.{$message}" ? trans("chat.{$message}") : $message);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $resolvedMessage,
                'details' => (object) [],
            ],
        ], $status, $headers)->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, list<string>> */
    private function safeRateLimitHeaders(Response $response): array
    {
        $safeHeaders = [];

        foreach ($response->headers->all() as $name => $values) {
            if ($name === 'retry-after' || str_starts_with($name, 'x-ratelimit-')) {
                $safeHeaders[$name] = array_map('strval', $values);
            }
        }

        return $safeHeaders;
    }

    private function isChatRequest(Request $request): bool
    {
        return $request->is('chat')
            || $request->is('chat/*')
            || $request->is('*/chat')
            || $request->is('*/chat/*');
    }
}
