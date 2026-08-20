<?php

namespace App\Http\Responses;

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
            return $this->error('rate_limited', 'rate_limited', 429);
        }

        if ($status >= 500) {
            return $this->error('chat_unavailable', 'unavailable', 500);
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function error(string $code, string $message, int $status): Response
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => trans("chat.{$message}"),
                'details' => (object) [],
            ],
        ], $status)->header('Cache-Control', 'no-store, private');
    }

    private function isChatRequest(Request $request): bool
    {
        return $request->is('chat')
            || $request->is('chat/*')
            || $request->is('*/chat')
            || $request->is('*/chat/*');
    }
}
