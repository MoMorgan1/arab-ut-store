<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminStaffRoleConflict extends Exception
{
    public function __construct(
        public readonly string $memberPublicId,
        public readonly string $currentRole,
        string $message = 'Staff role has changed.',
    ) {
        parent::__construct($message, 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'member' => $this->memberPublicId,
            'currentRole' => $this->currentRole,
        ], 409);
    }
}
