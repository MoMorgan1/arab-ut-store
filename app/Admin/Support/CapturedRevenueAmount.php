<?php

namespace App\Admin\Support;

use UnexpectedValueException;

final class CapturedRevenueAmount
{
    public function fromDatabase(mixed $amount): string
    {
        if ($amount === null) {
            return '0';
        }

        if (is_int($amount) && $amount >= 0) {
            return (string) $amount;
        }

        if (is_string($amount) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $amount) === 1) {
            return $amount;
        }

        throw new UnexpectedValueException('Captured revenue must be a nonnegative database integer.');
    }
}
