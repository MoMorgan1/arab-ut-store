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
            ...CartOwnerKey::canonical($ownerKey),
            'schedule_version' => $validated['scheduleVersion'],
            'platform' => $validated['platform'],
            'pc_store' => $validated['pcStore'] ?? null,
            'mode' => $validated['mode'],
            'current_division' => $validated['currentDivision'] ?? null,
            'target_division' => $validated['targetDivision'] ?? null,
            'credentials' => self::credentials($validated)->payload(),
            'squad_image_sha256' => self::imageHashOrNull($validated['squadImage'] ?? null),
            'replace_cart_item_id' => $validated['replaceCartItemId'] ?? null,
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

    /**
     * A replacement without a new upload keeps the old squad image, so
     * there is no file to hash — the null marks exactly that case.
     */
    private static function imageHashOrNull(mixed $file): ?string
    {
        if ($file === null) {
            return null;
        }

        return self::imageHash($file);
    }
}
