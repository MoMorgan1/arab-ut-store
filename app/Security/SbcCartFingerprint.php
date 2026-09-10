<?php

namespace App\Security;

final class SbcCartFingerprint
{
    /** @param array<string, mixed> $validated */
    public static function generate(string $ownerKey, array $validated, string $applicationKey): string
    {
        return hash_hmac('sha256', json_encode([
            ...CartOwnerKey::canonical($ownerKey),
            'variant_id' => $validated['variantId'],
            'completion_count' => (int) $validated['completionCount'],
            'replace_cart_item_id' => $validated['replaceCartItemId'] ?? null,
            'credentials' => [
                'ea_email' => $validated['credentials']['ea_email'],
                'ea_password' => $validated['credentials']['ea_password'],
                'backup_codes' => array_values($validated['credentials']['backup_codes']),
            ],
        ], JSON_THROW_ON_ERROR), $applicationKey);
    }
}
