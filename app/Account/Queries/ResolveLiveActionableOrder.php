<?php

namespace App\Account\Queries;

use App\Account\Presenters\LiveOrderCard;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;

final readonly class ResolveLiveActionableOrder
{
    public function __construct(private LiveOrderCard $presenter) {}

    /** @return array<string, mixed>|null */
    public function for(User $user, string $locale): ?array
    {
        $order = Order::query()
            ->select([
                'id',
                'public_id',
                'user_id',
                'order_number',
                'status',
                'currency',
                'wallet_halalah',
                'total_halalah',
                'placed_at',
                'created_at',
            ])
            ->where('user_id', $user->id)
            ->whereIn('status', $this->openStatuses())
            ->with(['items' => fn ($query) => $query
                ->select(['id', 'order_id', 'name_ar', 'name_en', 'status'])
                ->orderBy('id')])
            ->withExists(['payments as has_failed_payment' => fn ($query) => $query
                ->where('status', PaymentStatus::Failed->value)])
            ->orderByRaw(
                'CASE
                    WHEN orders.status = ? THEN 0
                    WHEN orders.status = ? AND EXISTS (
                        SELECT 1 FROM payments
                        WHERE payments.order_id = orders.id AND payments.status = ?
                    ) THEN 1
                    WHEN orders.status = ? THEN 2
                    WHEN orders.status = ? THEN 3
                    ELSE 4
                END',
                [
                    OrderStatus::WaitingForCustomer->value,
                    OrderStatus::PendingPayment->value,
                    PaymentStatus::Failed->value,
                    OrderStatus::PendingPayment->value,
                    OrderStatus::InProgress->value,
                ],
            )
            ->orderByRaw('COALESCE(orders.placed_at, orders.created_at) DESC')
            ->orderByDesc('orders.public_id')
            ->first();

        if (! $order instanceof Order) {
            return null;
        }

        return [
            ...$this->presenter->for($order, $locale),
            'action' => $this->action($order),
        ];
    }

    /** @return list<string> */
    private function openStatuses(): array
    {
        return [
            OrderStatus::PendingPayment->value,
            OrderStatus::Received->value,
            OrderStatus::InProgress->value,
            OrderStatus::WaitingForCustomer->value,
        ];
    }

    /** @return array{type: string} */
    private function action(Order $order): array
    {
        if ($order->status === OrderStatus::WaitingForCustomer) {
            return ['type' => 'provide_details'];
        }

        if ($order->status === OrderStatus::PendingPayment
            && (bool) $order->getAttribute('has_failed_payment')) {
            return ['type' => 'retry_payment'];
        }

        if ($order->status === OrderStatus::PendingPayment) {
            return ['type' => 'pay_now'];
        }

        return ['type' => 'view_order'];
    }
}
