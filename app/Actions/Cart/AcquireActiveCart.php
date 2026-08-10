<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AcquireActiveCart
{
    public function execute(CartOwner $owner): Cart
    {
        return DB::transaction(function () use ($owner): Cart {
            DB::table('carts')->insertOrIgnore([
                'public_id' => (string) Str::ulid(),
                'user_id' => $owner->userId(),
                'session_key' => $owner->sessionKey(),
                'status' => 'active',
                'currency' => 'SAR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return Cart::query()
                ->activeForOwner($owner)
                ->lockForUpdate()
                ->sole();
        }, attempts: 3);
    }
}
