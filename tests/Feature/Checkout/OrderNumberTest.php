<?php

use App\Checkout\OrderNumber;
use App\Models\Order;

test('order numbers are short, prefixed, and use the unambiguous alphabet', function (): void {
    foreach (range(1, 200) as $ignored) {
        expect(OrderNumber::candidate())->toMatch(OrderNumber::PATTERN);
    }
});

test('generate skips numbers that are already taken', function (): void {
    $existing = Order::factory()->create();

    $numbers = collect(range(1, 50))->map(fn (): string => OrderNumber::generate());

    expect($numbers->unique()->count())->toBe(50)
        ->and($numbers)->not->toContain($existing->order_number)
        ->and($numbers->every(fn (string $number): bool => preg_match(OrderNumber::PATTERN, $number) === 1))->toBeTrue();
});
