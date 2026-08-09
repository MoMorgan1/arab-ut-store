<?php

use App\Support\Money;

test('SAR arithmetic keeps exact integer halalah values', function () {
    $subtotal = Money::fromHalalah(12_345);

    $total = $subtotal
        ->plus(Money::fromHalalah(655))
        ->minus(Money::fromHalalah(500));

    expect($total->halalah())->toBe(12_500)
        ->and($subtotal->halalah())->toBe(12_345)
        ->and($total->currency())->toBe('SAR');
});

test('SAR arithmetic rejects negative amounts and underflow', function () {
    expect(fn () => Money::fromHalalah(-1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromHalalah(100)->minus(Money::fromHalalah(101)))
        ->toThrow(DomainException::class);
});

test('SAR multiplication uses an integer quantity without mutating the unit price', function () {
    $unitPrice = Money::fromHalalah(125);

    expect($unitPrice->multiply(3)->halalah())->toBe(375)
        ->and($unitPrice->halalah())->toBe(125);
});
