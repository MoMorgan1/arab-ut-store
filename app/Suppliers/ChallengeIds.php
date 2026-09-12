<?php

namespace App\Suppliers;

/**
 * Normalises and validates challenge (SBC) identifiers.
 *
 * Ports the tracker's SBC identifier handling from includes/functions.php:459-505.
 * Two methods exist because the tracker deliberately separates permissive parsing
 * from strict validation:
 * - parse(): extracts and normalises tokens without dropping malformed entries,
 *   so callers that need to report bad input can validate each result themselves.
 * - normalize(): keeps only syntactically valid challenge UUIDs, used for safe
 *   supplier calls, database storage, and lookups.
 */
final class ChallengeIds
{
    /**
     * The tracker's canonical UUID format for challenge identifiers.
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Parse a list or string of challenge IDs without dropping malformed IDs.
     *
     * Permissive: trims each token, strips the leading SBC- prefix case-insensitively,
     * lowercases, and de-duplicates while preserving first-seen order. Does not
     * filter out non-UUID tokens so callers can inspect and report malformed input.
     *
     * @param  string|array<array-key, mixed>  $input
     * @return list<string>
     */
    public static function parse(string|array $input): array
    {
        $rawItems = is_array($input) ? $input : [$input];
        $tokens = [];

        foreach ($rawItems as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            $split = preg_split('/[\s,]+/', (string) $item, -1, PREG_SPLIT_NO_EMPTY);
            if ($split !== false) {
                foreach ($split as $part) {
                    $tokens[] = $part;
                }
            }
        }

        $seen = [];
        $result = [];

        foreach ($tokens as $token) {
            $trimmed = trim($token);
            if ($trimmed === '') {
                continue;
            }

            $stripped = preg_replace('/^sbc-/i', '', $trimmed) ?? $trimmed;
            if ($stripped === '') {
                continue;
            }

            $lowercased = strtolower($stripped);

            if (! isset($seen[$lowercased])) {
                $seen[$lowercased] = true;
                $result[] = $lowercased;
            }
        }

        return $result;
    }

    /**
     * Normalise a list or string of challenge IDs, keeping only valid UUIDs.
     *
     * Strict: applies parse() and then filters to retain only syntactically valid
     * UUIDs matching the tracker's pattern. Used for storage, lookups, and API calls.
     *
     * @param  string|array<array-key, mixed>  $input
     * @return list<string>
     */
    public static function normalize(string|array $input): array
    {
        $parsed = self::parse($input);

        return array_values(array_filter(
            $parsed,
            static fn (string $id): bool => self::isValid($id),
        ));
    }

    /**
     * Alias for parse() matching the tracker's parseSBCIdList function name.
     *
     * @param  string|array<array-key, mixed>  $input
     * @return list<string>
     */
    public static function parseSbcIdList(string|array $input): array
    {
        return self::parse($input);
    }

    /**
     * Alias for normalize() matching the tracker's normalizeSBCIds function name.
     *
     * @param  string|array<array-key, mixed>  $input
     * @return list<string>
     */
    public static function normalizeSbcIds(string|array $input): array
    {
        return self::normalize($input);
    }

    /**
     * Check whether a challenge ID matches the canonical UUID format.
     */
    public static function isValid(string $id): bool
    {
        return preg_match(self::UUID_PATTERN, $id) === 1;
    }
}
