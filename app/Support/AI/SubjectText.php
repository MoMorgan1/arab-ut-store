<?php

namespace App\Support\AI;

use Illuminate\Support\Str;

/**
 * Turns a model's answer to the subject prompt into a title fit for the
 * widget header and history list, or nothing at all.
 */
final class SubjectText
{
    public const MAX_LENGTH = 80;

    public static function clean(string $raw): ?string
    {
        $firstLine = Str::of($raw)
            ->trim()
            ->explode("\n")
            ->map(fn (string $line): string => trim($line))
            ->first(fn (string $line): bool => $line !== '') ?? '';

        $title = preg_replace('/\s+/u', ' ', $firstLine) ?? '';

        // Quotes, a "Title:" label and trailing punctuation come off in
        // whatever order the model stacked them. Byte-wise trim() would eat
        // the lead byte of an Arabic letter, so every strip is a Unicode
        // regex.
        do {
            $before = $title;
            $title = preg_replace('/^[\s"\'«»“”‘’`]+|[\s"\'«»“”‘’`]+$/u', '', $title) ?? $title;
            $title = preg_replace('/^(?:title|subject|العنوان|عنوان)\s*[:：]\s*/iu', '', $title) ?? $title;
            $title = preg_replace('/[\s.。:：!؟?،,;]+$/u', '', $title) ?? $title;
        } while ($title !== $before);

        if ($title === '' || Str::length($title) > self::MAX_LENGTH) {
            return null;
        }

        return $title;
    }
}
