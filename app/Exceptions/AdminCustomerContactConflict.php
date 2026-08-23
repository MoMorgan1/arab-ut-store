<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminCustomerContactConflict extends Exception
{
    public function __construct(
        public readonly string $customerPublicId,
        public readonly string $currentUpdatedAt,
        string $message = 'Customer contact details have changed.',
    ) {
        parent::__construct($message, 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'customer' => $this->customerPublicId,
            'updatedAt' => $this->currentUpdatedAt,
        ], 409);
    }
}
