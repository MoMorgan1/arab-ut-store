<?php

namespace App\ValueObjects\Cart;

use DomainException;

final readonly class ManualServiceCredentials
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $maskedSummary
     */
    private function __construct(
        private array $payload,
        private array $maskedSummary,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        $platform = $validated['platform'] ?? null;

        return match ($platform) {
            'playstation' => self::playStation($validated),
            'pc' => self::pc($validated),
            default => throw new DomainException('The manual-service platform is unsupported.'),
        };
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    /** @return array<string, mixed> */
    public function maskedSummary(): array
    {
        return $this->maskedSummary;
    }

    /** @param array<string, mixed> $validated */
    private static function playStation(array $validated): self
    {
        self::requireExactKeys($validated, [
            'platform',
            'playstation_email',
            'playstation_password',
            'ea_backup_codes',
            'playstation_backup_codes',
        ]);

        $eaCodes = self::codes($validated['ea_backup_codes'], '/^[0-9]{8}$/D', false, 'EA');
        $playStationCodes = self::codes(
            $validated['playstation_backup_codes'],
            '/^[A-Za-z0-9]{6}$/D',
            true,
            'PlayStation',
        );

        return new self(
            payload: [
                'platform' => 'playstation',
                'playstation_email' => self::email($validated['playstation_email'], 'PlayStation'),
                'playstation_password' => self::password($validated['playstation_password'], 'PlayStation'),
                'ea_backup_codes' => $eaCodes,
                'playstation_backup_codes' => $playStationCodes,
            ],
            maskedSummary: self::summary('playstation', null, false, true, false, 3, 3),
        );
    }

    /** @param array<string, mixed> $validated */
    private static function pc(array $validated): self
    {
        $store = $validated['pc_store'] ?? null;

        if (! in_array($store, ['ea_app', 'steam'], true)) {
            throw new DomainException('The PC game launcher is unsupported.');
        }

        $expected = ['platform', 'pc_store', 'ea_email', 'ea_password', 'ea_backup_codes'];

        if ($store === 'steam') {
            $expected = [...$expected, 'steam_username', 'steam_password'];
        }

        self::requireExactKeys($validated, $expected);
        $payload = [
            'platform' => 'pc',
            'pc_store' => $store,
            'ea_email' => self::email($validated['ea_email'], 'EA'),
            'ea_password' => self::password($validated['ea_password'], 'EA'),
            'ea_backup_codes' => self::codes($validated['ea_backup_codes'], '/^[0-9]{8}$/D', false, 'EA'),
        ];

        if ($store === 'steam') {
            $payload['steam_username'] = self::username($validated['steam_username']);
            $payload['steam_password'] = self::password($validated['steam_password'], 'Steam');
        }

        return new self(
            payload: $payload,
            maskedSummary: self::summary('pc', $store, true, false, $store === 'steam', 3, 0),
        );
    }

    private static function email(mixed $value, string $provider): string
    {
        if (! is_string($value)) {
            throw new DomainException("The {$provider} email is invalid.");
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException("The {$provider} email is invalid.");
        }

        return $normalized;
    }

    private static function password(mixed $value, string $provider): string
    {
        if (! is_string($value) || $value === '') {
            throw new DomainException("The {$provider} password is required.");
        }

        return $value;
    }

    private static function username(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException('The Steam username is required.');
        }

        return trim($value);
    }

    /** @return list<string> */
    private static function codes(mixed $value, string $pattern, bool $uppercase, string $provider): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 3) {
            throw new DomainException("Exactly three {$provider} backup codes are required.");
        }

        $codes = [];

        foreach ($value as $code) {
            if (! is_string($code) || preg_match($pattern, $code) !== 1) {
                throw new DomainException("A {$provider} backup code has an invalid format.");
            }

            $codes[] = $uppercase ? strtoupper($code) : $code;
        }

        if (count(array_unique($codes, SORT_STRING)) !== 3) {
            throw new DomainException("The {$provider} backup codes must be different.");
        }

        return $codes;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $expected
     */
    private static function requireExactKeys(array $validated, array $expected): void
    {
        $actual = array_keys($validated);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new DomainException('The manual-service credentials contain unsupported or missing fields.');
        }
    }

    /** @return array<string, mixed> */
    private static function summary(
        string $platform,
        ?string $store,
        bool $hasEaPassword,
        bool $hasPlayStationPassword,
        bool $hasSteamPassword,
        int $eaCodeCount,
        int $playStationCodeCount,
    ): array {
        return [
            'platform' => $platform,
            'pc_store' => $store,
            'has_ea_password' => $hasEaPassword,
            'has_playstation_password' => $hasPlayStationPassword,
            'has_steam_password' => $hasSteamPassword,
            'ea_backup_code_count' => $eaCodeCount,
            'playstation_backup_code_count' => $playStationCodeCount,
        ];
    }
}
