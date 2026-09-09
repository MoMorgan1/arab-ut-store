<?php

namespace App\Security;

final class CatalogCartFingerprint
{
    public static function generate(string $ownerKey, string $variantPublicId, string $applicationKey): string
    {
        return hash_hmac('sha256', json_encode([
            ...CartOwnerKey::canonical($ownerKey),
            'variant_id' => $variantPublicId,
        ], JSON_THROW_ON_ERROR), $applicationKey);
    }
}
