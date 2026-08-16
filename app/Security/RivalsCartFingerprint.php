<?php

namespace App\Security;

use App\ValueObjects\Cart\ManualServiceCredentials;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class RivalsCartFingerprint
{
    /** @param array<string, mixed> $validated */
    public static function generate(string $ownerKey, array $validated, string $applicationKey): string
    {
        return hash_hmac('sha256', json_encode([
            ...self::canonicalOwner($ownerKey),
            'schedule_version' => $validated['scheduleVersion'],
            'platform' => $validated['platform'],
            'pc_store' => $validated['pcStore'] ?? null,
            'current_division' => $validated['currentDivision'],
            'target_division' => $validated['targetDivision'],
            'credentials' => self::credentials($validated)->payload(),
            'squad_image_sha256' => self::imageHash($validated['squadImage'] ?? null),
        ], JSON_THROW_ON_ERROR), $applicationKey);
    }

    /** @param array<string, mixed> $validated */
    private static function credentials(array $validated): ManualServiceCredentials
    {
        $credentials = ['platform' => $validated['platform'], ...$validated['credentials']];

        if ($validated['platform'] === 'pc') {
            $credentials['pc_store'] = $validated['pcStore'];
        }

        return ManualServiceCredentials::fromValidated($credentials);
    }

    private static function imageHash(mixed $file): string
    {
        if (! $file instanceof UploadedFile || ! is_string($file->getRealPath())) {
            throw new InvalidArgumentException('The Rivals squad image is invalid.');
        }

        $hash = hash_file('sha256', $file->getRealPath());

        if (! is_string($hash)) {
            throw new InvalidArgumentException('The Rivals squad image could not be fingerprinted.');
        }

        return $hash;
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
