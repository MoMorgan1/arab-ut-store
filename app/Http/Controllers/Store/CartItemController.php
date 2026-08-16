<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\DeleteCartItemFulfillment;
use App\Actions\Cart\ResolveCartOwner;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CartItemController extends Controller
{
    public function destroy(
        Request $request,
        ResolveCartOwner $resolveCartOwner,
        DeleteCartItemFulfillment $deleteFulfillment,
    ): JsonResponse {
        $cartItem = (string) $request->route('cartItem');
        $cartCount = DB::transaction(function () use ($request, $cartItem, $resolveCartOwner, $deleteFulfillment): int {
            $ownedCartIds = Cart::query();
            $ownedCartIds->activeForOwner($resolveCartOwner->forRequest($request));

            $ownedItem = CartItem::query()
                ->where('public_id', $cartItem)
                ->whereIn('cart_id', $ownedCartIds->select('id'))
                ->lockForUpdate()
                ->firstOrFail();
            $cartId = $ownedItem->cart_id;

            $deleteFulfillment->execute($ownedItem);
            $ownedItem->delete();

            return CartItem::query()->where('cart_id', $cartId)->count();
        }, attempts: 3);

        return response()->json(['data' => ['cartCount' => $cartCount]]);
    }
}
