<?php

namespace App\Support;

use App\Models\ChatConversation;
use RuntimeException;

/**
 * Short, human-friendly, non-sequential conversation numbers such as CHT-7K4QXM.
 *
 * Mirrors App\Checkout\OrderNumber: the alphabet omits 0/O and 1/I so numbers
 * survive being read aloud or typed from a screenshot, and uniqueness is
 * verified against the table before use.
 */
final class ChatNumber
{
    public const PREFIX = 'CHT-';

    public const LENGTH = 6;

    public const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const PATTERN = '/^CHT-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/';

    private const MAX_ATTEMPTS = 10;

    public static function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = self::candidate();

            if (ChatConversation::query()->where('short_id', $candidate)->doesntExist()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a unique conversation number.');
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
