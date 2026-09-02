<?php

namespace App\Services\Reviews;

use App\Enums\OrderItemStatus;
use App\Enums\ServiceType;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * Single source of truth for attributing a review to a service.
 */
final class ResolveReviewService
{
    public static function forOrderItem(OrderItem $item): ?string
    {
        $service = $item->getAttribute('service_type');

        if ($service instanceof ServiceType) {
            return $service->value;
        }

        if (is_string($service) && $service !== '') {
            return ServiceType::tryFrom($service)?->value;
        }

        return null;
    }

    public static function forOrder(Order $order): ?string
    {
        $items = $order->relationLoaded('items')
            ? $order->items
            : $order->items()->get();

        $services = $items
            ->reject(fn (OrderItem $item): bool => in_array($item->status, [
                OrderItemStatus::Cancelled,
                OrderItemStatus::Refunded,
            ], true))
            ->map(fn (OrderItem $item): ?string => self::forOrderItem($item))
            ->filter()
            ->unique()
            ->values();

        return $services->count() === 1 ? $services->first() : null;
    }
}
