<?php

namespace App\Http\Controllers\Automation;

use App\Actions\Pricing\ApplyCoinsPricingRun;
use App\Exceptions\CoinsPricingEventReplay;
use App\Exceptions\CoinsPricingRunReplay;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\CoinsPricingRunRequest;
use Illuminate\Http\JsonResponse;

final class CoinsPricingRunController extends Controller
{
    public function __invoke(
        CoinsPricingRunRequest $request,
        ApplyCoinsPricingRun $applyCoinsPricingRun,
    ): JsonResponse {
        try {
            $summary = $applyCoinsPricingRun->execute($request->validated());
        } catch (CoinsPricingEventReplay) {
            return response()->json([
                'error' => [
                    'code' => 'coins_pricing_event_replayed',
                    'message' => 'The Coins pricing event has already been processed.',
                ],
            ], 409)->header('Cache-Control', 'no-store');
        } catch (CoinsPricingRunReplay) {
            return response()->json([
                'error' => [
                    'code' => 'coins_pricing_run_replayed',
                    'message' => 'The Coins pricing run has already been processed.',
                ],
            ], 409)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $summary], 201)
            ->header('Cache-Control', 'no-store');
    }
}
