<?php

namespace App\Admin\Audit;

use InvalidArgumentException;

final readonly class StaffAuditEvent
{
    /** @var list<string> */
    private const FORBIDDEN_METADATA_KEY_PARTS = [
        'password',
        'credential',
        'secret',
        'token',
        'recovery_code',
        'encrypted_payload',
        'provider_metadata',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $action,
        public array $metadata,
        public ?string $ipAddress,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+\z/', $action) !== 1) {
            throw new InvalidArgumentException('Audit action names must be stable dotted identifiers.');
        }

        if ($ipAddress !== null && strlen($ipAddress) > 45) {
            throw new InvalidArgumentException('Audit IP addresses may not exceed 45 characters.');
        }

        $this->assertMetadataIsSafe($metadata);
    }

    /** @param array<array-key, mixed> $metadata */
    private function assertMetadataIsSafe(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && $this->isForbiddenMetadataKey($key)) {
                throw new InvalidArgumentException('Audit metadata may not contain secrets.');
            }

            if (is_array($value)) {
                $this->assertMetadataIsSafe($value);

                continue;
            }

            if (! $this->isJsonSafeMetadataValue($value)) {
                throw new InvalidArgumentException('Audit metadata values must be JSON-safe.');
            }
        }
    }

    private function isForbiddenMetadataKey(string $key): bool
    {
        $camelSeparated = preg_replace(
            '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/',
            '_',
            $key,
        );
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($camelSeparated ?? $key)), '_');

        foreach (self::FORBIDDEN_METADATA_KEY_PARTS as $forbidden) {
            if (preg_match('/(?:^|_)'.preg_quote($forbidden, '/').'(?:_|$)/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isJsonSafeMetadataValue(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        return is_string($value) && preg_match('//u', $value) === 1;
    }
}
