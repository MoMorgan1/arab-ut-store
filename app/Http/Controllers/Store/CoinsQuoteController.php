<?php

namespace App\Http\Controllers\Store;

use App\Actions\Pricing\ConvertDisplayMoney;
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
    public function __invoke(
        CoinsQuoteRequest $request,
        QuoteCoins $quoteCoins,
        ConvertDisplayMoney $convertDisplayMoney,
    ): JsonResponse {
        $validated = $request->safe();

        try {
            $quote = $quoteCoins->execute(
                Platform::from($validated->string('platform')->toString()),
                $validated->filled('delivery')
                    ? DeliveryMode::from($validated->string('delivery')->toString())
                    : null,
                $validated->integer('quantity'),
            );
            $displayTotal = $convertDisplayMoney->execute(
                $quote->total,
                (string) $request->session()->get('display_currency'),
            );
        } catch (DomainException|ValueError) {
            return response()->json([
                'error' => [
                    'code' => 'coins_pricing_unavailable',
                    'message' => trans('store.quote.unavailable'),
                ],
            ], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $quote->toArray($displayTotal)])
            ->header('Cache-Control', 'no-store');
    }
}
