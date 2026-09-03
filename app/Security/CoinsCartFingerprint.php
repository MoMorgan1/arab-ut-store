<?php

namespace App\Security;

use InvalidArgumentException;

final class CoinsCartFingerprint
{
    /** @param array<string, mixed> $validated */
    public static function generate(string $ownerKey, array $validated, string $applicationKey): string
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $validated['credentials'];
        $canonicalCredentials = [
            'ea_email' => $credentials['ea_email'],
            'ea_password' => $credentials['ea_password'],
            'backup_codes' => array_values($credentials['backup_codes']),
        ];

        if (array_key_exists('companion_market_open', $credentials)
            || array_key_exists('policy_accepted', $credentials)
            || array_key_exists('current_balance', $credentials)) {
            $canonicalCredentials = [
                ...$canonicalCredentials,
                'current_balance' => $credentials['current_balance'] ?? null,
                'companion_market_open' => (bool) ($credentials['companion_market_open'] ?? false),
                'policy_accepted' => (bool) ($credentials['policy_accepted'] ?? false),
            ];
        }

        $canonicalRequest = [
            ...self::canonicalOwner($ownerKey),
            'platform' => $validated['platform'],
            'delivery' => $validated['delivery'] ?? null,
            'quantity' => (int) $validated['quantity'],
            'replace_cart_item_id' => $validated['replaceCartItemId'] ?? null,
            'credentials' => $canonicalCredentials,
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
