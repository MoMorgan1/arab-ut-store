<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminStaffStatusConflict extends Exception
{
    public function __construct(
        public readonly string $memberPublicId,
        public readonly bool $currentActive,
        string $message = 'Staff status has changed.',
    ) {
        parent::__construct($message, 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'member' => $this->memberPublicId,
            'isActive' => $this->currentActive,
        ], 409);
    }
}
