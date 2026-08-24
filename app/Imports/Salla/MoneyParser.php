<?php

namespace App\Imports\Salla;

final class MoneyParser
{
    /**
     * Parse a decimal or integer string to integer minor units (halalah/cents)
     * purely using string splitting and integer arithmetic. Never floats.
     */
    public static function parse(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        $raw = trim((string) $value);
        if ($raw === '' || $raw === '\N' || $raw === 'NULL') {
            return 0;
        }

        // Remove any commas or spaces (e.g., '1,234.50')
        $clean = str_replace([',', ' '], '', $raw);

        $negative = str_starts_with($clean, '-');
        if ($negative) {
            $clean = substr($clean, 1);
        }

        // Check if there is a decimal point
        $parts = explode('.', $clean, 2);
        $wholePart = preg_replace('/\D/', '', $parts[0]);
        $wholePart = ($wholePart !== null && $wholePart !== '') ? $wholePart : '0';
        $fractionPart = isset($parts[1]) ? (preg_replace('/\D/', '', $parts[1]) ?? '') : '';

        $whole = (int) $wholePart;

        if ($fractionPart === '') {
            $fraction = 0;
        } elseif (strlen($fractionPart) === 1) {
            $fraction = (int) ($fractionPart.'0');
        } elseif (strlen($fractionPart) === 2) {
            $fraction = (int) $fractionPart;
        } else {
            // 3+ digits: round to 2 digits
            $twoDigits = (int) substr($fractionPart, 0, 2);
            $thirdDigit = (int) $fractionPart[2];
            $fraction = $thirdDigit >= 5 ? $twoDigits + 1 : $twoDigits;
        }

        $result = ($whole * 100) + $fraction;

        return $negative ? -$result : $result;
    }
}
