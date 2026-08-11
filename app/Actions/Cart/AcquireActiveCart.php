<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AcquireActiveCart
{
    public function __construct(private LockGuestCartClaims $lockGuestCartClaims) {}

    public function execute(CartOwner $owner): Cart
    {
        return DB::transaction(function () use ($owner): Cart {
            $effectiveOwner = $this->effectiveOwner($owner);

            DB::table('carts')->insertOrIgnore([
                'public_id' => (string) Str::ulid(),
                'user_id' => $effectiveOwner->userId(),
                'session_key' => $effectiveOwner->sessionKey(),
                'status' => 'active',
                'currency' => 'SAR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return Cart::query()
                ->activeForOwner($effectiveOwner)
                ->lockForUpdate()
                ->sole();
        }, attempts: 3);
    }

    private function effectiveOwner(CartOwner $owner): CartOwner
    {
        $guestSessionHmac = $owner->sessionKey();

        if ($guestSessionHmac === null) {
            return $owner;
        }

        $claimedUserId = $this->lockGuestCartClaims->execute([$owner])[$guestSessionHmac];

        return $claimedUserId === null ? $owner : CartOwner::user($claimedUserId);
    }
}
