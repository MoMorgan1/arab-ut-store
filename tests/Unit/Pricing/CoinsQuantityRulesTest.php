<?php

use App\ValueObjects\Pricing\CoinsQuantityRules;

function quantityRules(array $changes = []): CoinsQuantityRules
{
    return CoinsQuantityRules::fromConfiguration(array_replace([
        'minimum' => 5_000,
        'tiers' => [
            ['upTo' => 50_000, 'step' => 5_000],
            ['upTo' => 500_000, 'step' => 10_000],
            ['upTo' => 2_000_000, 'step' => 50_000],
            ['upTo' => 20_000_000, 'step' => 250_000],
        ],
        'presets' => [50_000, 100_000, 500_000, 1_000_000, 5_000_000],
    ], $changes));
}

test('the step widens as the quantity climbs', function () {
    $rules = quantityRules();

    // The whole point: 10,000 steps are sensible at fifty thousand coins and
    // absurd at three million, where the slider would need 300 nudges.
    expect($rules->stepAt(20_000))->toBe(5_000)
        ->and($rules->stepAt(200_000))->toBe(10_000)
        ->and($rules->stepAt(1_000_000))->toBe(50_000)
        ->and($rules->stepAt(3_000_000))->toBe(250_000);
});

test('it accepts a quantity that lands on its own band and rejects one between steps', function () {
    $rules = quantityRules();

    expect($rules->accepts(5_000))->toBeTrue()
        ->and($rules->accepts(45_000))->toBeTrue()
        ->and($rules->accepts(1_000_000))->toBeTrue()
        ->and($rules->accepts(20_000_000))->toBeTrue()
        ->and($rules->accepts(7_500))->toBeFalse()
        ->and($rules->accepts(1_000))->toBeFalse()
        ->and($rules->accepts(20_000_001))->toBeFalse();
});

test('the schedule stays small enough to price ahead of time', function () {
    $quantities = quantityRules()->legalQuantities();

    // A flat 5,000 step over the same range would be 4,000 entries, and 1,000
    // would be 19,996. Every one of these is priced on every quote build.
    expect(count($quantities))->toBeLessThan(200)
        ->and($quantities[0])->toBe(5_000)
        ->and($quantities[count($quantities) - 1])->toBe(20_000_000);
});

test('every legal quantity is accepted, and the list has no gaps', function () {
    $rules = quantityRules();

    foreach ($rules->legalQuantities() as $quantity) {
        expect($rules->accepts($quantity))->toBeTrue("{$quantity} is generated but rejected");
    }
});

test('a band that does not divide by its own step is rejected', function () {
    // This is the class of mistake that used to throw deep inside the quote
    // builder at request time instead of when the setting was saved.
    expect(fn () => quantityRules(['tiers' => [['upTo' => 50_000, 'step' => 5_000], ['upTo' => 100_001, 'step' => 10_000]]]))
        ->toThrow(DomainException::class);
});

test('bands must ascend without overlapping', function () {
    expect(fn () => quantityRules(['tiers' => [['upTo' => 500_000, 'step' => 5_000], ['upTo' => 50_000, 'step' => 5_000]]]))
        ->toThrow(DomainException::class);
});

test('a preset a customer could never select is rejected', function () {
    expect(fn () => quantityRules(['presets' => [7_500]]))->toThrow(DomainException::class)
        ->and(fn () => quantityRules(['presets' => [1_000]]))->toThrow(DomainException::class);
});

test('the minimum and every ceiling must be a positive integer', function (array $changes) {
    expect(fn () => quantityRules($changes))->toThrow(DomainException::class);
})->with([
    'zero minimum' => [['minimum' => 0]],
    'negative minimum' => [['minimum' => -5_000]],
    'string step' => [['tiers' => [['upTo' => 50_000, 'step' => '5000']]]],
    'no tiers' => [['tiers' => []]],
    'unknown tier field' => [['tiers' => [['upTo' => 50_000, 'step' => 5_000, 'label' => 'small']]]],
]);
