<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\AddRivalsToCart;
use App\Actions\Cart\ResolveCartOwner;
use App\Exceptions\Cart\DuplicateCartItem;
use App\Exceptions\Cart\ReplacedCartItemMissing;
use App\Exceptions\IdempotencyConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\RivalsCartRequest;
use DomainException;
use Illuminate\Http\JsonResponse;

final class RivalsCartController extends Controller
{
    public function __invoke(
        RivalsCartRequest $request,
        AddRivalsToCart $addToCart,
        ResolveCartOwner $resolveCartOwner,
    ): JsonResponse {
        try {
            $addition = $addToCart->execute(
                $resolveCartOwner->forRequest($request),
                $request->validated(),
                $request->idempotencyKey(),
                (string) app()->getLocale(),
            );
        } catch (IdempotencyConflict) {
            return $this->error('idempotency_conflict', trans('store.cart.idempotency_conflict'), 409);
        } catch (DuplicateCartItem) {
            return $this->duplicate();
        } catch (ReplacedCartItemMissing) {
            return $this->error('replaced_item_unavailable', trans('store.cart.replaced_item_unavailable'), 404);
        } catch (DomainException) {
            return $this->error('manual_service_unavailable', trans('store.cart.manual_service_unavailable'), 422);
        }

        return response()->json($addition['body'], $addition['status'])
            ->header('Cache-Control', 'no-store');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => compact('code', 'message')], $status)
            ->header('Cache-Control', 'no-store');
    }

    private function duplicate(): JsonResponse
    {
        $locale = (string) app()->getLocale();
        $cartUrl = $locale === 'en'
            ? route('localized.store.cart', ['locale' => 'en'], absolute: false)
            : route('store.cart', [], absolute: false);

        return response()->json(['error' => [
            'code' => 'already_in_cart',
            'message' => trans('store.cart.already_in_cart'),
            'cartUrl' => $cartUrl,
        ]], 409)->header('Cache-Control', 'no-store');
    }
}
