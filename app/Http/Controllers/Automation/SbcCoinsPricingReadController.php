<?php

namespace App\Http\Controllers\Automation;

use App\Actions\Pricing\ReadSbcCoinsPricingBases;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;

final class SbcCoinsPricingReadController extends Controller
{
    public function __invoke(ReadSbcCoinsPricingBases $readSbcCoinsPricingBases): JsonResponse
    {
        try {
            $bases = $readSbcCoinsPricingBases->execute();
        } catch (DomainException) {
            return response()->json([
                'error' => [
                    'code' => 'sbc_pricing_unavailable',
                    'message' => 'The current Coins pricing bases are unavailable.',
                ],
            ], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json($bases)
            ->header('Cache-Control', 'no-store');
    }
}
