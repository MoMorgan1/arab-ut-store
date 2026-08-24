<?php

namespace App\Imports\Salla;

use App\ValueObjects\E164Phone;
use DomainException;

final class PhoneNormalizer
{
    /**
     * Normalise a mobile string into E.164 (+[1-9][0-9]{7,14}).
     * Returns null if empty or cannot be parsed as valid E.164.
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $raw = trim($phone);
        if ($raw === '' || $raw === '\N' || $raw === 'NULL') {
            return null;
        }

        // If it starts with 00, convert to +
        if (str_starts_with($raw, '00')) {
            $raw = '+'.substr($raw, 2);
        }

        // If it doesn't start with +, but starts with 05 (Saudi local), add +966
        if (! str_starts_with($raw, '+')) {
            if (str_starts_with($raw, '05') && strlen($raw) === 10) {
                $raw = '+966'.substr($raw, 1);
            } elseif (preg_match('/^[1-9][0-9]{7,14}$/', $raw)) {
                $raw = '+'.$raw;
            }
        }

        try {
            return E164Phone::from($raw)->value();
        } catch (DomainException) {
            if (preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $raw)) {
                return $raw;
            }

            return null;
        }
    }
}
