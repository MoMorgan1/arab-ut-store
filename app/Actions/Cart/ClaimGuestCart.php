<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\User;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ClaimGuestCart
{
    public function execute(string $guestSessionHmac, User $user): void
    {
        $guestOwner = CartOwner::guest($guestSessionHmac);
        $userOwner = CartOwner::user((int) $user->getAuthIdentifier());

        DB::transaction(
            fn () => $this->claim($guestOwner, $userOwner, $user),
            attempts: 3,
        );
    }

    private function claim(CartOwner $guestOwner, CartOwner $userOwner, User $user): void
    {
        $activeCarts = $this->lockedActiveCarts($guestOwner, $userOwner);
        $guestCart = $activeCarts->get($guestOwner->databaseKey());

        if (! $guestCart instanceof Cart) {
            return;
        }

        $userCart = $activeCarts->get($userOwner->databaseKey());

        if ($userCart instanceof Cart) {
            $this->mergeCarts($guestCart, $userCart);

            return;
        }

        $this->convertGuestCart($guestCart, $user);
    }

    /** @return Collection<string, Cart> */
    private function lockedActiveCarts(CartOwner $guestOwner, CartOwner $userOwner): Collection
    {
        return Cart::query()
            ->select(['id', 'active_owner_key'])
            ->where('status', 'active')
            ->where('currency', 'SAR')
            ->whereIn('active_owner_key', [$guestOwner->databaseKey(), $userOwner->databaseKey()])
            ->orderBy('active_owner_key')
            ->lockForUpdate()
            ->get()
            ->keyBy('active_owner_key');
    }

    private function convertGuestCart(Cart $guestCart, User $user): void
    {
        DB::table('carts')->where('id', $guestCart->id)->update([
            'user_id' => $user->id,
            'session_key' => null,
            'updated_at' => now(),
        ]);
    }

    private function mergeCarts(Cart $guestCart, Cart $userCart): void
    {
        DB::table('cart_items')
            ->where('cart_id', $guestCart->id)
            ->update(['cart_id' => $userCart->id, 'updated_at' => now()]);
        DB::table('carts')->where('id', $guestCart->id)->delete();
    }
}
