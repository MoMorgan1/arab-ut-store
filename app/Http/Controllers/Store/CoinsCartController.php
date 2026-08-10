<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\AddCoinsToCart;
use App\Exceptions\IdempotencyConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CoinsCartRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use ValueError;

final class CoinsCartController extends Controller
{
    public function __invoke(CoinsCartRequest $request, AddCoinsToCart $addCoins): JsonResponse
    {
        try {
            $cartAddition = $addCoins->execute(
                $request->user(),
                $request->validated(),
                $request->idempotencyKey(),
                (string) app()->getLocale(),
            );
        } catch (IdempotencyConflict) {
            return $this->errorResponse('idempotency_conflict', trans('store.cart.idempotency_conflict'), 409);
        } catch (DomainException|ValueError) {
            return $this->errorResponse('coins_pricing_unavailable', trans('store.quote.unavailable'), 503);
        }

        return response()->json($cartAddition['body'], $cartAddition['status'])
            ->header('Cache-Control', 'no-store');
    }

    private function errorResponse(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], $status)->header('Cache-Control', 'no-store');
    }
}
