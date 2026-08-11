<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\User;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ClaimGuestCart
{
    public function __construct(private LockGuestCartClaims $lockGuestCartClaims) {}

    /** @param list<CartOwner> $guestOwners */
    public function execute(array $guestOwners, User $user): void
    {
        $userOwner = CartOwner::user((int) $user->getAuthIdentifier());

        DB::transaction(
            fn () => $this->claim($guestOwners, $userOwner, $user),
            attempts: 3,
        );
    }

    /** @param list<CartOwner> $guestOwners */
    private function claim(array $guestOwners, CartOwner $userOwner, User $user): void
    {
        $claims = $this->lockGuestCartClaims->execute($guestOwners);
        $this->ensureClaimsBelongToUser($claims, (int) $user->getAuthIdentifier());
        $activeCarts = $this->lockedActiveCarts($guestOwners, $userOwner);
        $userCart = $activeCarts->get($userOwner->databaseKey());
        $guestCarts = $activeCarts
            ->filter(fn (Cart $cart): bool => $cart->active_owner_key !== $userOwner->databaseKey())
            ->values();

        if (! $userCart instanceof Cart) {
            $userCart = $guestCarts->shift();

            if ($userCart instanceof Cart) {
                $this->convertGuestCart($userCart, $user);
            }
        }

        if ($userCart instanceof Cart) {
            $guestCarts->each(fn (Cart $guestCart) => $this->mergeCarts($guestCart, $userCart));
        }

        DB::table('guest_cart_claims')
            ->whereIn('guest_session_hmac', array_keys($claims))
            ->update([
                'user_id' => $user->id,
                'claimed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<CartOwner>  $guestOwners
     * @return Collection<string, Cart>
     */
    private function lockedActiveCarts(array $guestOwners, CartOwner $userOwner): Collection
    {
        $ownerKeys = array_map(
            fn (CartOwner $owner): string => $owner->databaseKey(),
            [...$guestOwners, $userOwner],
        );

        return Cart::query()
            ->select(['id', 'active_owner_key'])
            ->where('status', 'active')
            ->where('currency', 'SAR')
            ->whereIn('active_owner_key', $ownerKeys)
            ->orderBy('active_owner_key')
            ->lockForUpdate()
            ->get()
            ->keyBy('active_owner_key');
    }

    /** @param array<string, int|null> $claims */
    private function ensureClaimsBelongToUser(array $claims, int $userId): void
    {
        foreach ($claims as $claimedUserId) {
            if ($claimedUserId !== null && $claimedUserId !== $userId) {
                throw new RuntimeException('The guest cart has already been claimed.');
            }
        }
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
