<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CartController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $activeCart = $request->user() === null ? null : Cart::query()
            ->where('active_owner_key', "user:{$request->user()->id}")
            ->with(['items.secret'])
            ->first();
        $safeCartItems = $activeCart?->items
            ->map(fn (CartItem $cartItem): array => $this->safeCartItem($cartItem))
            ->values()
            ->all() ?? [];

        return Inertia::render('store/simple-page', [
            'page' => [
                'key' => 'cart',
                'title' => trans('ui.simple_pages.cart.title'),
                'body' => trans('ui.simple_pages.cart.body'),
            ],
            'cart' => [
                'count' => count($safeCartItems),
                'currency' => 'SAR',
                'items' => $safeCartItems,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function safeCartItem(CartItem $cartItem): array
    {
        return [
            'id' => $cartItem->public_id,
            'quantity' => $cartItem->quantity,
            'unitPriceHalalah' => $cartItem->unit_price_halalah,
            'totalHalalah' => $cartItem->total_halalah,
            'configuration' => $cartItem->configuration,
            'requiresCredentials' => $cartItem->secret === null
                || $cartItem->secret->getRawOriginal('encrypted_payload') === null
                || $cartItem->secret->deleted_at !== null,
        ];
    }
}
