<?php

namespace App\Actions\Cart;

use App\ValueObjects\Cart\CartOwner;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LockGuestCartClaims
{
    /**
     * @param  list<CartOwner>  $guestOwners
     * @return array<string, int|null>
     */
    public function execute(array $guestOwners): array
    {
        $guestSessionHmacs = $this->guestSessionHmacs($guestOwners);
        $now = now();
        $existingGuestSessionHmacs = DB::table('guest_cart_claims')
            ->whereIn('guest_session_hmac', $guestSessionHmacs)
            ->orderBy('guest_session_hmac')
            ->lockForUpdate()
            ->pluck('guest_session_hmac')
            ->map(fn (mixed $guestSessionHmac): string => (string) $guestSessionHmac)
            ->all();
        $missingGuestSessionHmacs = array_values(array_diff(
            $guestSessionHmacs,
            $existingGuestSessionHmacs,
        ));

        DB::table('guest_cart_claims')->insertOrIgnore(array_map(
            fn (string $guestSessionHmac): array => [
                'guest_session_hmac' => $guestSessionHmac,
                'user_id' => null,
                'claimed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $missingGuestSessionHmacs,
        ));

        return DB::table('guest_cart_claims')
            ->whereIn('guest_session_hmac', $guestSessionHmacs)
            ->orderBy('guest_session_hmac')
            ->lockForUpdate()
            ->get(['guest_session_hmac', 'user_id'])
            ->mapWithKeys(fn (object $claim): array => [
                (string) $claim->guest_session_hmac => $claim->user_id === null
                    ? null
                    : (int) $claim->user_id,
            ])
            ->all();
    }

    /**
     * @param  list<CartOwner>  $guestOwners
     * @return list<string>
     */
    private function guestSessionHmacs(array $guestOwners): array
    {
        $guestSessionHmacs = [];

        foreach ($guestOwners as $guestOwner) {
            $guestSessionHmac = $guestOwner->sessionKey();

            if ($guestOwner->userId() !== null || $guestSessionHmac === null) {
                throw new InvalidArgumentException('A guest cart claim requires guest owners.');
            }

            $guestSessionHmacs[$guestSessionHmac] = $guestSessionHmac;
        }

        if ($guestSessionHmacs === []) {
            throw new InvalidArgumentException('At least one guest cart owner is required.');
        }

        sort($guestSessionHmacs, SORT_STRING);

        return $guestSessionHmacs;
    }
}
