<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminCustomerStatusConflict extends Exception
{
    public function __construct(
        public readonly string $customerPublicId,
        public readonly bool $currentActive,
        string $message = 'Customer status has changed.',
    ) {
        parent::__construct($message, 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'customer' => $this->customerPublicId,
            'isActive' => $this->currentActive,
        ], 409);
    }
}
