<?php

namespace App\Http\Controllers\Automation;

use App\Actions\Pricing\ReadCoinsPricingBaseline;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;

final class CoinsPricingBaselineController extends Controller
{
    public function __invoke(ReadCoinsPricingBaseline $readBaseline): JsonResponse
    {
        try {
            $baseline = $readBaseline->execute();
        } catch (DomainException) {
            // A store that has never published has no baseline, and that is a
            // fact rather than a fault: the first run has nothing to carry and
            // says so on its own terms.
            return response()->json([
                'error' => [
                    'code' => 'coins_pricing_baseline_unavailable',
                    'message' => 'No applied Coins pricing run carries a baseline.',
                ],
            ], 404)->header('Cache-Control', 'no-store');
        }

        return response()->json($baseline)->header('Cache-Control', 'no-store');
    }
}
