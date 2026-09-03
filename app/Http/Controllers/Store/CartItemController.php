<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\AssertVariantNotInCart;
use App\Actions\Cart\ResolveCartOwner;
use App\Exceptions\Cart\DuplicateCartItem;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class CartItemController extends Controller
{
    public function destroy(
        Request $request,
        ResolveCartOwner $resolveCartOwner,
    ): JsonResponse {
        $cartItem = (string) $request->route('cartItem');
        $result = DB::transaction(function () use ($request, $cartItem, $resolveCartOwner): array {
            $ownedCartIds = Cart::query();
            $ownedCartIds->activeForOwner($resolveCartOwner->forRequest($request));

            $ownedItem = CartItem::query()
                ->where('public_id', $cartItem)
                ->whereIn('cart_id', $ownedCartIds->select('id'))
                ->lockForUpdate()
                ->firstOrFail();
            $cartId = $ownedItem->cart_id;

            $ownedItem->update(['removed_at' => now()]);

            $cartCount = CartItem::query()->where('cart_id', $cartId)->count();

            return ['cartCount' => $cartCount, 'publicId' => $ownedItem->public_id];
        }, attempts: 3);

        return response()->json(['data' => [
            'cartCount' => $result['cartCount'],
            'restoreUrl' => $this->restoreUrl($request, $result['publicId']),
        ]]);
    }

    public function restore(
        Request $request,
        ResolveCartOwner $resolveCartOwner,
        AssertVariantNotInCart $assertVariantNotInCart,
    ): JsonResponse {
        $cartItem = (string) $request->route('cartItem');

        try {
            $cartCount = DB::transaction(function () use ($request, $cartItem, $resolveCartOwner, $assertVariantNotInCart): int {
                $ownedCartIds = Cart::query();
                $ownedCartIds->activeForOwner($resolveCartOwner->forRequest($request));

                $ownedItem = CartItem::query()
                    ->withRemoved()
                    ->where('public_id', $cartItem)
                    ->whereIn('cart_id', $ownedCartIds->select('id'))
                    ->whereNotNull('removed_at')
                    ->where('removed_at', '>=', now()->subMinutes(30))
                    ->lockForUpdate()
                    ->first();

                if (! $ownedItem instanceof CartItem) {
                    abort(Response::HTTP_NOT_FOUND);
                }

                $cart = Cart::query()
                    ->whereKey($ownedItem->cart_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                try {
                    $assertVariantNotInCart->execute($cart, $ownedItem->productVariant);
                } catch (DuplicateCartItem) {
                    abort(response()->json([
                        'error' => ['code' => 'already_in_cart'],
                    ], Response::HTTP_CONFLICT));
                }

                $ownedItem->update(['removed_at' => null]);

                return CartItem::query()->where('cart_id', $cart->id)->count();
            }, attempts: 3);
        } catch (DuplicateCartItem) {
            return response()->json([
                'error' => ['code' => 'already_in_cart'],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => ['cartCount' => $cartCount]]);
    }

    private function restoreUrl(Request $request, string $publicId): string
    {
        $localized = $request->route('locale') === 'en';

        return route(
            $localized ? 'localized.cart.items.restore' : 'cart.items.restore',
            [...($localized ? ['locale' => 'en'] : []), 'cartItem' => $publicId],
            absolute: false,
        );
    }
}
