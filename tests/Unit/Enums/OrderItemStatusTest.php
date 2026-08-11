<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusHistoryStatus;

test('order item status vocabulary includes terminal failure', function () {
    expect(array_column(OrderItemStatus::cases(), 'value'))
        ->toBe([
            'pending_payment',
            'received',
            'in_progress',
            'waiting_for_customer',
            'completed',
            'cancelled',
            'refunded',
            'failed',
        ]);
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
