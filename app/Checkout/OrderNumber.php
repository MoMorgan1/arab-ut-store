<?php

namespace App\Checkout;

use App\Models\Order;
use RuntimeException;

/**
 * Short, human-friendly, non-sequential order numbers such as AUT-7K4QXM.
 *
 * The alphabet omits 0/O and 1/I so numbers survive being read aloud or
 * typed from a screenshot; six characters give ~1.07 billion combinations
 * and uniqueness is still verified against the orders table before use.
 */
final class OrderNumber
{
    public const PREFIX = 'AUT-';

    public const LENGTH = 6;

    public const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const PATTERN = '/^AUT-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/';

    private const MAX_ATTEMPTS = 10;

    public static function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = self::candidate();

            if (Order::query()->where('order_number', $candidate)->doesntExist()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a unique order number.');
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
