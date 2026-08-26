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

test('the slider ladder stays small enough to price ahead of time', function () {
    $stops = quantityRules()->sliderStops();

    // Every stop is priced on every quote build. A flat 5,000 ladder over the
    // same range would be 4,000 entries; a typed quantity is quoted on its own
    // request instead, which is why the ladder can stay short.
    expect(count($stops))->toBeLessThan(200)
        ->and($stops[0])->toBe(5_000)
        ->and($stops[count($stops) - 1])->toBe(20_000_000);
});

test('every slider stop is a quantity a customer may buy', function () {
    // The slider must never park on something the cart would then refuse.
    $rules = quantityRules();

    foreach ($rules->sliderStops() as $quantity) {
        expect($rules->accepts($quantity))->toBeTrue("{$quantity} is a stop but is rejected");
    }
});

test('a typed quantity is rounded to the unit, not dragged onto a band', function () {
    $rules = quantityRules();

    // 155,000 sits between two band steps and is bought exactly as typed.
    expect($rules->accepts(155_000))->toBeTrue()
        ->and($rules->round(155_000))->toBe(155_000)
        ->and($rules->round(152_300))->toBe(150_000)
        ->and($rules->round(152_500))->toBe(155_000)
        ->and($rules->round(154_999))->toBe(155_000);
});

test('rounding clamps to the floor and the ceiling', function () {
    $rules = quantityRules();

    expect($rules->round(1))->toBe(5_000)
        ->and($rules->round(0))->toBe(5_000)
        ->and($rules->round(-100))->toBe(5_000)
        ->and($rules->round(999_999_999))->toBe(20_000_000);
});

test('the rounding unit is what the pricing run has to publish', function () {
    // n8n declares one increment per group. It is the unit, not the finest
    // band, or the run would advertise a coarser grid than the store sells on.
    expect(quantityRules()->finestStep())->toBe(5_000)
        ->and(quantityRules(['roundingUnit' => 1_000])->finestStep())->toBe(1_000);
});

test('a band step that is not a whole number of units is rejected', function () {
    // Otherwise a slider stop would land somewhere the cart refuses.
    expect(fn () => quantityRules([
        'roundingUnit' => 5_000,
        'tiers' => [['upTo' => 27_000, 'step' => 11_000]],
        'presets' => [],
    ]))->toThrow(DomainException::class);
});

test('a floor that is not a whole number of units is rejected', function () {
    expect(fn () => quantityRules([
        'minimum' => 7_000,
        'roundingUnit' => 5_000,
        'tiers' => [['upTo' => 57_000, 'step' => 5_000]],
        'presets' => [],
    ]))->toThrow(DomainException::class);
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
