<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Derives the short subject shown for a conversation or ticket from the first
 * customer message.
 *
 * `support_tickets.subject` is a string(160), so 160 is the hard ceiling here
 * too — the same helper feeds the ticket record and the widget history list, so
 * a customer never sees two different summaries of the same thread.
 */
final class SubjectPreview
{
    public const MAX_LENGTH = 160;

    /**
     * Collapse whitespace and truncate on a word boundary.
     *
     * Str::words() is not used: it counts space-separated words, which is wrong
     * for Arabic-heavy text where a "word" carries far more characters than a
     * Latin one, so a fixed word count produces wildly different lengths per
     * locale. Truncating by characters and then trimming back to the last
     * boundary gives both locales the same visual budget.
     */
    public static function fromMessage(?string $content, string $fallback = ''): string
    {
        $normalised = trim(preg_replace('/\s+/u', ' ', (string) $content) ?? '');

        if ($normalised === '') {
            return $fallback;
        }

        if (Str::length($normalised) <= self::MAX_LENGTH) {
            return $normalised;
        }

        $clipped = Str::substr($normalised, 0, self::MAX_LENGTH);
        $lastSpace = mb_strrpos($clipped, ' ');

        // A single unbroken token longer than the ceiling has no boundary to
        // fall back to; a hard cut is the only option that respects the column.
        if ($lastSpace !== false && $lastSpace > 0) {
            $clipped = Str::substr($clipped, 0, $lastSpace);
        }

        return rtrim($clipped).'…';
    }
}
