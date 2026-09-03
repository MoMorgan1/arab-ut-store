<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\AddCatalogItemToCart;
use App\Actions\Cart\ResolveCartOwner;
use App\Exceptions\Cart\DuplicateCartItem;
use App\Exceptions\IdempotencyConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CatalogCartRequest;
use DomainException;
use Illuminate\Http\JsonResponse;

final class CatalogCartController extends Controller
{
    public function __invoke(
        CatalogCartRequest $request,
        AddCatalogItemToCart $addCatalogItem,
        ResolveCartOwner $resolveCartOwner,
    ): JsonResponse {
        try {
            $addition = $addCatalogItem->execute(
                $resolveCartOwner->forRequest($request),
                (string) $request->validated('variantId'),
                $request->idempotencyKey(),
                (string) app()->getLocale(),
            );
        } catch (IdempotencyConflict) {
            return $this->error('idempotency_conflict', trans('store.cart.idempotency_conflict'), 409);
        } catch (DuplicateCartItem) {
            return $this->duplicate();
        } catch (DomainException) {
            return $this->error('catalog_item_unavailable', trans('store.cart.catalog_item_unavailable'), 422);
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
