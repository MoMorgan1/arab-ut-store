<?php

namespace App\Security;

use InvalidArgumentException;

final class CoinsCartFingerprint
{
    /** @param array<string, mixed> $validated */
    public static function generate(string $ownerKey, array $validated, string $applicationKey): string
    {
        $canonicalRequest = [
            ...self::canonicalOwner($ownerKey),
            'platform' => $validated['platform'],
            'delivery' => $validated['delivery'] ?? null,
            'quantity' => (int) $validated['quantity'],
            'credentials' => [
                'ea_email' => $validated['credentials']['ea_email'],
                'ea_password' => $validated['credentials']['ea_password'],
                'backup_codes' => array_values($validated['credentials']['backup_codes']),
                'current_balance' => $validated['credentials']['current_balance'] ?? null,
                'companion_market_open' => (bool) $validated['credentials']['companion_market_open'],
                'policy_accepted' => (bool) $validated['credentials']['policy_accepted'],
            ],
        ];

        return hash_hmac(
            'sha256',
            json_encode($canonicalRequest, JSON_THROW_ON_ERROR),
            $applicationKey,
        );
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
