<?php

namespace App\Enums;

/**
 * The two external services that actually deliver an automated order.
 *
 * The asymmetry matters and is load-bearing elsewhere: FFT serves both coins
 * and challenges, UTT serves coins only. So a challenge item can never be
 * placed with UTT, and a supplier choice is not a free swap.
 */
enum Supplier: string
{
    case Fft = 'fft';
    case Utt = 'utt';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $supplier): string => $supplier->value, self::cases());
    }

    public function handlesChallenges(): bool
    {
        return $this === self::Fft;
    }
}
