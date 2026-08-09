<?php

namespace App\Http\Controllers\Store;

use App\Actions\Pricing\QuoteCoins;
use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CoinsQuoteRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use ValueError;

class CoinsQuoteController extends Controller
{
    public function __invoke(CoinsQuoteRequest $request, QuoteCoins $quoteCoins): JsonResponse
    {
        $validated = $request->safe();

        try {
            $quote = $quoteCoins->execute(
                Platform::from($validated->string('platform')->toString()),
                $validated->filled('delivery')
                    ? DeliveryMode::from($validated->string('delivery')->toString())
                    : null,
                $validated->integer('quantity'),
            );
        } catch (DomainException|ValueError) {
            return response()->json([
                'error' => [
                    'code' => 'coins_pricing_unavailable',
                    'message' => trans('store.quote.unavailable'),
                ],
            ], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $quote->toArray()])
            ->header('Cache-Control', 'no-store');
    }
}
