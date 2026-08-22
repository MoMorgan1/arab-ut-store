<?php

use App\Admin\Support\OrderStatusTransitionRules;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;

test('allowedTargets returns the approved transitions for active order statuses', function (): void {
    $rules = new OrderStatusTransitionRules;

    expect($rules->allowedTargets(OrderStatus::PendingPayment))->toBe([
        OrderStatus::Cancelled,
    ]);

    expect($rules->allowedTargets(OrderStatus::Received))->toBe([
        OrderStatus::InProgress,
        OrderStatus::WaitingForCustomer,
        OrderStatus::Completed,
        OrderStatus::Cancelled,
    ]);

    expect($rules->allowedTargets(OrderStatus::InProgress))->toBe([
        OrderStatus::WaitingForCustomer,
        OrderStatus::Completed,
        OrderStatus::Cancelled,
    ]);

    expect($rules->allowedTargets(OrderStatus::WaitingForCustomer))->toBe([
        OrderStatus::InProgress,
        OrderStatus::Completed,
        OrderStatus::Cancelled,
    ]);
});

test('terminal statuses have no allowed transitions', function (OrderStatus $terminal): void {
    $rules = new OrderStatusTransitionRules;

    expect($rules->allowedTargets($terminal))->toBe([])
        ->and($rules->itemTargets($terminal, OrderStatus::InProgress))->toBeNull()
        ->and($rules->itemTargets($terminal, OrderStatus::Cancelled))->toBeNull();
})->with([
    OrderStatus::Completed,
    OrderStatus::Cancelled,
    OrderStatus::Refunded,
]);

test('transitions targeting refunded are always rejected', function (OrderStatus $from): void {
    $rules = new OrderStatusTransitionRules;

    expect($rules->allowedTargets($from))->not->toContain(OrderStatus::Refunded)
        ->and($rules->itemTargets($from, OrderStatus::Refunded))->toBeNull()
        ->and($rules->isAllowed($from, OrderStatus::Refunded))->toBeFalse();
})->with(OrderStatus::cases());

test('illegal pairs return null item targets and false for isAllowed', function (
    OrderStatus $from,
    OrderStatus $to,
): void {
    $rules = new OrderStatusTransitionRules;

    expect($rules->itemTargets($from, $to))->toBeNull()
        ->and($rules->isAllowed($from, $to))->toBeFalse();
})->with([
    'pending_payment to received' => [OrderStatus::PendingPayment, OrderStatus::Received],
    'pending_payment to in_progress' => [OrderStatus::PendingPayment, OrderStatus::InProgress],
    'pending_payment to waiting_for_customer' => [OrderStatus::PendingPayment, OrderStatus::WaitingForCustomer],
    'pending_payment to completed' => [OrderStatus::PendingPayment, OrderStatus::Completed],
    'received to pending_payment' => [OrderStatus::Received, OrderStatus::PendingPayment],
    'in_progress to pending_payment' => [OrderStatus::InProgress, OrderStatus::PendingPayment],
    'in_progress to received' => [OrderStatus::InProgress, OrderStatus::Received],
    'waiting_for_customer to pending_payment' => [OrderStatus::WaitingForCustomer, OrderStatus::PendingPayment],
    'waiting_for_customer to received' => [OrderStatus::WaitingForCustomer, OrderStatus::Received],
    'completed to in_progress' => [OrderStatus::Completed, OrderStatus::InProgress],
    'cancelled to received' => [OrderStatus::Cancelled, OrderStatus::Received],
    'refunded to completed' => [OrderStatus::Refunded, OrderStatus::Completed],
]);

test('itemTargets maps the approved source statuses for each legal transition', function (): void {
    $rules = new OrderStatusTransitionRules;

    $activeItemStatuses = [
        OrderItemStatus::PendingPayment,
        OrderItemStatus::Received,
        OrderItemStatus::InProgress,
        OrderItemStatus::WaitingForCustomer,
    ];

    // Cancellation from any active order status targets all non-terminal active items
    expect($rules->itemTargets(OrderStatus::PendingPayment, OrderStatus::Cancelled))->toBe($activeItemStatuses);
    expect($rules->itemTargets(OrderStatus::Received, OrderStatus::Cancelled))->toBe($activeItemStatuses);
    expect($rules->itemTargets(OrderStatus::InProgress, OrderStatus::Cancelled))->toBe($activeItemStatuses);
    expect($rules->itemTargets(OrderStatus::WaitingForCustomer, OrderStatus::Cancelled))->toBe($activeItemStatuses);

    // Moves between active statuses
    expect($rules->itemTargets(OrderStatus::Received, OrderStatus::InProgress))->toBe([
        OrderItemStatus::Received,
    ]);
    expect($rules->itemTargets(OrderStatus::Received, OrderStatus::WaitingForCustomer))->toBe([
        OrderItemStatus::Received,
    ]);
    expect($rules->itemTargets(OrderStatus::InProgress, OrderStatus::WaitingForCustomer))->toBe([
        OrderItemStatus::InProgress,
    ]);
    expect($rules->itemTargets(OrderStatus::WaitingForCustomer, OrderStatus::InProgress))->toBe([
        OrderItemStatus::WaitingForCustomer,
    ]);

    // Completion propagates items whose status equals the order's previous status
    expect($rules->itemTargets(OrderStatus::Received, OrderStatus::Completed))->toBe([
        OrderItemStatus::Received,
    ]);
    expect($rules->itemTargets(OrderStatus::InProgress, OrderStatus::Completed))->toBe([
        OrderItemStatus::InProgress,
    ]);
    expect($rules->itemTargets(OrderStatus::WaitingForCustomer, OrderStatus::Completed))->toBe([
        OrderItemStatus::WaitingForCustomer,
    ]);
});
