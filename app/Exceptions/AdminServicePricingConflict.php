<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminServicePricingConflict extends Exception
{
    /**
     * @param  array<string, mixed>  $currentConfiguration
     */
    public function __construct(
        public readonly string $serviceType,
        public readonly int $currentVersion,
        public readonly bool $currentActive,
        public readonly array $currentConfiguration,
        string $message = 'The service pricing schedule was modified by another operator.',
    ) {
        parent::__construct($message, 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'serviceType' => $this->serviceType,
            'version' => $this->currentVersion,
            'isActive' => $this->currentActive,
            'configuration' => $this->currentConfiguration,
            'message' => $this->getMessage(),
        ], 409)
            ->header('Cache-Control', 'no-store, private')
            ->header('Content-Type', 'application/json');
    }
}
