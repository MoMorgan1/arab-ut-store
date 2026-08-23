<?php

namespace App\Http\Controllers\Chat;

use App\Actions\AI\BuildServicePriceLabels;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Starting prices for the assistant's service cards.
 *
 * These deliberately do not travel in the Inertia shared props. Shared props are
 * built for every request, and the storefront enforces per-page query budgets
 * that pricing lookups would blow. The widget asks for them once, only when it
 * actually has a card to price.
 */
final class ServicePriceController extends Controller
{
    public function __invoke(Request $request, BuildServicePriceLabels $prices): JsonResponse
    {
        $displayCurrency = (string) (
            $request->session()->get('display_currency')
            ?? config('store.default_display_currency')
        );

        return response()->json(['prices' => $prices->execute($displayCurrency)]);
    }
}
