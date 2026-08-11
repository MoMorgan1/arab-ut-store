<?php

namespace App\ValueObjects;

use DomainException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final readonly class E164Phone
{
    private function __construct(private string $value) {}

    public static function from(string $candidate): self
    {
        $candidate = trim($candidate);

        try {
            $phone = PhoneNumberUtil::getInstance()->parse($candidate, null);
        } catch (NumberParseException) {
            throw new DomainException('The phone number is invalid.');
        }

        $util = PhoneNumberUtil::getInstance();

        if (! $util->isValidNumber($phone)) {
            throw new DomainException('The phone number is invalid.');
        }

        $canonical = $util->format($phone, PhoneNumberFormat::E164);

        if ($canonical !== $candidate) {
            throw new DomainException('The phone number must use canonical E.164 format.');
        }

        return new self($canonical);
    }

    public function value(): string
    {
        return $this->value;
    }
}
