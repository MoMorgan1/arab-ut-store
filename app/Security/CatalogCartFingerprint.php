<?php

namespace App\Security;

use InvalidArgumentException;

final class CatalogCartFingerprint
{
    public static function generate(string $ownerKey, string $variantPublicId, string $applicationKey): string
    {
        return hash_hmac('sha256', json_encode([
            ...self::canonicalOwner($ownerKey),
            'variant_id' => $variantPublicId,
        ], JSON_THROW_ON_ERROR), $applicationKey);
    }

    /** @return array{user_id: int}|array{owner_key: string} */
    private static function canonicalOwner(string $ownerKey): array
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
