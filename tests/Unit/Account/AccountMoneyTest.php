<?php

use App\Account\Presenters\AccountMoney;

test('minor-unit money stays exact even at the integer boundary', function (): void {
    expect(AccountMoney::fromMinor(PHP_INT_MAX, 'SAR'))->toBe([
        'amountMinor' => (string) PHP_INT_MAX,
        'currency' => 'SAR',
    ]);
});

test('minor-unit money rejects values that violate the account contract', function (
    int $amount,
    string $currency,
): void {
    expect(fn () => AccountMoney::fromMinor($amount, $currency))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'negative amount' => [-1, 'SAR'],
    'lowercase currency' => [100, 'sar'],
    'invalid currency length' => [100, 'SA'],
]);
