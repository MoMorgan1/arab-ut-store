<?php

use App\Checkout\OrderNumber;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

test('numbers count upward and stay readable', function (): void {
    $numbers = collect(range(1, 25))->map(
        fn (): string => DB::transaction(fn (): string => OrderNumber::generate()),
    );
    $values = $numbers->map(fn (string $number): int => (int) substr($number, strlen(OrderNumber::PREFIX)));

    expect($numbers->unique())->toHaveCount(25)
        ->and($numbers->every(fn (string $number): bool => OrderNumber::matches($number)))->toBeTrue()
        ->and($values->toArray())->toBe($values->sort()->values()->toArray());
});

test('consecutive numbers do not publish how many orders arrived between them', function (): void {
    // Strictly consecutive numbers let any customer who orders twice subtract
    // and read the store's volume. The step varies so that subtraction says
    // nothing reliable.
    $values = collect(range(1, 40))
        ->map(fn (): string => DB::transaction(fn (): string => OrderNumber::generate()))
        ->map(fn (string $number): int => (int) substr($number, strlen(OrderNumber::PREFIX)));
    $steps = $values->sliding(2)->map(fn ($pair): int => $pair->last() - $pair->first());

    expect($steps->every(fn (int $step): bool => $step >= 1))->toBeTrue()
        ->and($steps->unique()->count())->toBeGreaterThan(1);
});

test('the older random numbers are still recognised', function (): void {
    expect(OrderNumber::matches('AUT-7K4QXM'))->toBeTrue()
        ->and(OrderNumber::matches('AUT-1043'))->toBeTrue()
        ->and(OrderNumber::matches('AUT-0043'))->toBeFalse()
        ->and(OrderNumber::matches('nonsense'))->toBeFalse();
});

test('it steps past a legacy number the counter would collide with', function (): void {
    // The legacy alphabet included digits 2-9, so an old order can hold a
    // number this counter will eventually reach. order_number is unique, so
    // walking into one would fail a checkout.
    DB::table('order_number_sequence')->update(['next_value' => 234567]);
    Order::factory()->create(['order_number' => 'AUT-234567']);

    $number = DB::transaction(fn (): string => OrderNumber::generate());

    expect($number)->not->toBe('AUT-234567')
        ->and(OrderNumber::matches($number))->toBeTrue();
});
