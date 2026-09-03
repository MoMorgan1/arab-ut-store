<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\AddCoinsToCart;
use App\Actions\Cart\ResolveCartOwner;
use App\Exceptions\Cart\DuplicateCartItem;
use App\Exceptions\IdempotencyConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CoinsCartRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use ValueError;

final class CoinsCartController extends Controller
{
    public function __invoke(
        CoinsCartRequest $request,
        AddCoinsToCart $addCoins,
        ResolveCartOwner $resolveCartOwner,
    ): JsonResponse {
        try {
            $cartAddition = $addCoins->execute(
                $resolveCartOwner->forRequest($request),
                $request->validated(),
                $request->idempotencyKey(),
                (string) app()->getLocale(),
            );
        } catch (IdempotencyConflict) {
            return $this->errorResponse('idempotency_conflict', trans('store.cart.idempotency_conflict'), 409);
        } catch (DuplicateCartItem) {
            return response()->json([
                'error' => [
                    'code' => 'already_in_cart',
                    'message' => trans('store.cart.already_in_cart'),
                    'cartUrl' => $this->cartUrl(),
                ],
            ], 409)->header('Cache-Control', 'no-store');
        } catch (DomainException|ValueError $exception) {
            // The customer still gets the same generic 503, but the cause is no
            // longer thrown away. Without this an operator seeing this status in
            // production has nothing to debug from, and a failing test reports
            // only "expected 201, got 503" while the real reason is discarded.
            report($exception);

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

    private function cartUrl(): string
    {
        $locale = (string) app()->getLocale();

        return $locale === 'en'
            ? route('localized.store.cart', ['locale' => 'en'], absolute: false)
            : route('store.cart', [], absolute: false);
    }
}
