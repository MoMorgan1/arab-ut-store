<?php

namespace App\Security;

final class CoinsCartFingerprint
{
    /** @param array<string, mixed> $validated */
    public static function generate(string $ownerKey, array $validated, string $applicationKey): string
    {
        $canonicalRequest = [
            'owner_key' => $ownerKey,
            'platform' => $validated['platform'],
            'delivery' => $validated['delivery'] ?? null,
            'quantity' => (int) $validated['quantity'],
            'credentials' => [
                'ea_email' => $validated['credentials']['ea_email'],
                'ea_password' => $validated['credentials']['ea_password'],
                'backup_codes' => array_values($validated['credentials']['backup_codes']),
            ],
        ];

        return hash_hmac(
            'sha256',
            json_encode($canonicalRequest, JSON_THROW_ON_ERROR),
            $applicationKey,
        );
    }
}
