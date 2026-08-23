<?php

namespace App\Customers;

use App\Models\User;
use RuntimeException;

/**
 * Short, human-friendly, non-sequential customer numbers such as CUS-7K4QXM.
 *
 * Mirrors App\Checkout\OrderNumber: the alphabet omits 0/O and 1/I so a number
 * survives being read aloud or typed from a screenshot, and uniqueness is
 * verified against the users table before use. The ULID public_id remains the
 * identifier used by routes and the API; this is the one support staff say out
 * loud.
 */
final class CustomerNumber
{
    public const PREFIX = 'CUS-';

    public const LENGTH = 6;

    public const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const PATTERN = '/^CUS-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/';

    private const MAX_ATTEMPTS = 10;

    public static function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = self::candidate();

            if (User::query()->where('customer_number', $candidate)->doesntExist()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a unique customer number.');
    }

    public static function candidate(): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $number = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $number .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return self::PREFIX.$number;
    }
}
