<?php

namespace App\Http\Responses;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ChatErrorResponse
{
    public function render(Response $response, Throwable $exception, Request $request): Response
    {
        if (! $this->isChatRequest($request)) {
            return $response;
        }

        $contract = $this->errorContract($response, $exception);

        if ($contract === null) {
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }

        return $this->jsonResponse($contract);
    }

    /** @param array{code: string, message: string, status: int} $contract */
    private function jsonResponse(array $contract): Response
    {
        return response()->json([
            'error' => [
                'code' => $contract['code'],
                'message' => trans($contract['message']),
                'details' => (object) [],
            ],
        ], $contract['status'])->header('Cache-Control', 'no-store, private');
    }

    /** @return array{code: string, message: string, status: int}|null */
    private function errorContract(Response $response, Throwable $exception): ?array
    {
        return match (true) {
            $response->getStatusCode() === 422 && $exception instanceof ValidationException => [
                'code' => 'validation_error',
                'message' => 'chat.validation_error',
                'status' => 422,
            ],
            $response->getStatusCode() === 429 && $exception instanceof ThrottleRequestsException => [
                'code' => 'rate_limited',
                'message' => 'chat.rate_limited',
                'status' => 429,
            ],
            $response->getStatusCode() >= 500 => [
                'code' => 'chat_unavailable',
                'message' => 'chat.unavailable',
                'status' => 500,
            ],
            default => null,
        };
    }

    private function isChatRequest(Request $request): bool
    {
        return $request->is('chat')
            || $request->is('chat/*')
            || $request->is('*/chat')
            || $request->is('*/chat/*');
    }
}
