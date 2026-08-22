<?php

namespace App\Admin\Support;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;

final class OrderStatusTransitionRules
{
    /**
     * @return list<OrderStatus>
     */
    public function allowedTargets(OrderStatus $from): array
    {
        return match ($from) {
            OrderStatus::PendingPayment => [
                OrderStatus::Cancelled,
            ],
            OrderStatus::Received => [
                OrderStatus::InProgress,
                OrderStatus::WaitingForCustomer,
                OrderStatus::Completed,
                OrderStatus::Cancelled,
            ],
            OrderStatus::InProgress => [
                OrderStatus::WaitingForCustomer,
                OrderStatus::Completed,
                OrderStatus::Cancelled,
            ],
            OrderStatus::WaitingForCustomer => [
                OrderStatus::InProgress,
                OrderStatus::Completed,
                OrderStatus::Cancelled,
            ],
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::Refunded => [],
        };
    }

    /**
     * @return list<OrderItemStatus>|null
     */
    public function itemTargets(OrderStatus $from, OrderStatus $to): ?array
    {
        if ($to === OrderStatus::Refunded) {
            return null;
        }

        if (! in_array($to, $this->allowedTargets($from), true)) {
            return null;
        }

        if ($to === OrderStatus::Cancelled) {
            return [
                OrderItemStatus::PendingPayment,
                OrderItemStatus::Received,
                OrderItemStatus::InProgress,
                OrderItemStatus::WaitingForCustomer,
            ];
        }

        return match ([$from, $to]) {
            [OrderStatus::Received, OrderStatus::InProgress] => [
                OrderItemStatus::Received,
            ],
            [OrderStatus::Received, OrderStatus::WaitingForCustomer] => [
                OrderItemStatus::Received,
            ],
            [OrderStatus::Received, OrderStatus::Completed] => [
                OrderItemStatus::Received,
            ],
            [OrderStatus::InProgress, OrderStatus::WaitingForCustomer] => [
                OrderItemStatus::InProgress,
            ],
            [OrderStatus::InProgress, OrderStatus::Completed] => [
                OrderItemStatus::InProgress,
            ],
            [OrderStatus::WaitingForCustomer, OrderStatus::InProgress] => [
                OrderItemStatus::WaitingForCustomer,
            ],
            [OrderStatus::WaitingForCustomer, OrderStatus::Completed] => [
                OrderItemStatus::WaitingForCustomer,
            ],
            default => null,
        };
    }

    public function isAllowed(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to, $this->allowedTargets($from), true);
    }
}
