<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;

test('an item status is an order status and nothing else', function () {
    // There used to be a 'failed' case here that nothing ever assigned, yet it
    // shipped a customer-facing label. An item state no order can reach is a
    // state no customer can be told the truth about.
    expect(array_column(OrderItemStatus::cases(), 'value'))
        ->toBe(array_column(OrderStatus::cases(), 'value'));
});

test('status history boundary covers every approved order and item status', function () {
    $approvedValues = collect([...OrderStatus::cases(), ...OrderItemStatus::cases()])
        ->map->value
        ->unique()
        ->sort()
        ->values()
        ->all();
    $historyValues = collect(OrderStatusHistoryStatus::cases())
        ->map->value
        ->sort()
        ->values()
        ->all();

    expect($historyValues)->toBe($approvedValues);
});
