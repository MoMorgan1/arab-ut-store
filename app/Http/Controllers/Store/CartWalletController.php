<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\ResolveCartOwner;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CartWalletController extends Controller
{
    public function store(Request $request, ResolveCartOwner $resolveCartOwner): JsonResponse
    {
        $validated = $request->validate([
            'use' => ['required', 'boolean'],
        ]);

        $activeCart = Cart::query()
            ->activeForOwner($resolveCartOwner->forRequest($request))
            ->first();

        if (! $activeCart instanceof Cart) {
            return response()
                ->json(['error' => ['message' => 'Cart not found']], 404)
                ->header('Cache-Control', 'no-store, private');
        }

        $activeCart->update([
            'use_wallet' => (bool) $validated['use'],
        ]);

        return response()
            ->json(['data' => ['use_wallet' => (bool) $activeCart->use_wallet]])
            ->header('Cache-Control', 'no-store, private');
    }
}
