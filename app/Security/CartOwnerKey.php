<?php

namespace App\Security;

use InvalidArgumentException;

/**
 * The owner half of every cart fingerprint. A signed-in customer is
 * identified by id so a re-login keeps the same cart; a guest by the
 * 64-hex key the cookie carries.
 */
final class CartOwnerKey
{
    /** @return array{user_id: int}|array{owner_key: string} */
    public static function canonical(string $ownerKey): array
    {
        if (preg_match('/\Auser:([1-9][0-9]*)\z/D', $ownerKey, $matches) === 1) {
            return ['user_id' => (int) $matches[1]];
        }

        if (preg_match('/\Aguest:[0-9a-f]{64}\z/D', $ownerKey) === 1) {
            return ['owner_key' => $ownerKey];
        }

        throw new InvalidArgumentException('The cart fingerprint owner is invalid.');
    }
}
