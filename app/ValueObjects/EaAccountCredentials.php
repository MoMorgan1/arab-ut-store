<?php

namespace App\ValueObjects;

use DomainException;

/**
 * One definition of valid EA account details, shared by checkout and the
 * customer's credential correction so the two cannot drift apart.
 *
 * A backup code is six OR eight digits (owner decision, 2026-09-13): the
 * tracker accepts either and the store previously accepted only eight, which
 * left a customer with six-digit codes unservable. The pattern is the single
 * implementation of that rule and is exposed so the correction form can reuse
 * it rather than restating it.
 */
final readonly class EaAccountCredentials
{
    public const string BACKUP_CODE_PATTERN = '/\A(?:[0-9]{6}|[0-9]{8})\z/D';

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $maskedSummary
     */
    private function __construct(
        private array $payload,
        private array $maskedSummary,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $email = self::email($validated['ea_email'] ?? null);
        $password = self::password($validated['ea_password'] ?? null);
        $codes = self::codes($validated['backup_codes'] ?? null);

        return new self(
            payload: [
                'ea_email' => $email,
                'ea_password' => $password,
                'backup_codes' => $codes,
            ],
            // The summary must never carry the password or a full backup code,
            // so it stores only the facts a reader needs: that a password is
            // present and how many codes were supplied.
            maskedSummary: [
                'has_ea_password' => true,
                'backup_code_count' => count($codes),
            ],
        );
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

    /**
     * The supplier-neutral credential vocabulary both clients read.
     *
     * @return array{user: string, pass: string, ba: string, ba2: string, ba3: string}
     */
    public function toSupplierCredentials(): array
    {
        $codes = $this->payload['backup_codes'];

        return [
            'user' => $this->payload['ea_email'],
            'pass' => $this->payload['ea_password'],
            'ba' => $codes[0],
            'ba2' => $codes[1],
            'ba3' => $codes[2],
        ];
    }

    private static function email(mixed $value): string
    {
        if (! is_string($value)) {
            throw new DomainException('The EA email is invalid.');
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('The EA email is invalid.');
        }

        return $normalized;
    }

    private static function password(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new DomainException('The EA password is required.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function codes(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 3) {
            throw new DomainException('Exactly three EA backup codes are required.');
        }

        $codes = [];

        foreach ($value as $code) {
            if (! is_string($code) || preg_match(self::BACKUP_CODE_PATTERN, $code) !== 1) {
                throw new DomainException('An EA backup code has an invalid format.');
            }

            $codes[] = $code;
        }

        if (count(array_unique($codes, SORT_STRING)) !== 3) {
            throw new DomainException('The EA backup codes must be different.');
        }

        return $codes;
    }
}
