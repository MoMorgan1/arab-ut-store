<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminOrderStatusConflict extends Exception
{
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $currentStatus,
        string $message = 'Order status has changed.',
    ) {
        parent::__construct($message, 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'order' => $this->orderNumber,
            'status' => $this->currentStatus,
        ], 409);
    }
}
