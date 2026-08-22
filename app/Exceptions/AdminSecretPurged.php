<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminSecretPurged extends Exception
{
    public function __construct(
        string $message = 'Secret has been purged or expired.',
    ) {
        parent::__construct($message, 410);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'secret_purged',
        ], 410);
    }
}
