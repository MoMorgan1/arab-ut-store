<?php

namespace App\Account\Presenters;

use InvalidArgumentException;

final class AccountMoney
{
    /** @return array{amountMinor: string, currency: string} */
    public static function fromMinor(int $amountMinor, string $currency): array
    {
        if ($amountMinor < 0) {
            throw new InvalidArgumentException('Account money cannot be negative.');
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Account money requires an uppercase ISO currency code.');
        }

        return [
            'amountMinor' => (string) $amountMinor,
            'currency' => $currency,
        ];
    }
}
