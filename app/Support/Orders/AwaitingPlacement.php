<?php

namespace App\Support\Orders;

use App\Enums\OrderItemStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * The items on an order that a supplier still has to be asked to deliver.
 *
 * One definition, read by the three places an order becomes paid and by the
 * publisher that composes the placement request, so "does n8n need to hear
 * about this order" and "what does it need to hear" cannot disagree:
 *
 * - an automated service (Coins, SBC) - a booster delivers the rest, and n8n
 *   has nothing to place for those (owner decision, 2026-09-14: an order of
 *   manual services alone sends no event);
 * - with no fulfillment job - a job exists only once a placement was
 *   recorded, whether n8n reported it or staff pasted the reference on a
 *   manual order, and a reference pasted means "already placed" (owner
 *   decision, 2026-09-14: a manual order without one is dispatched like any
 *   other);
 * - and still open - an item cancelled or refunded between payment and a
 *   retried send must not reach a supplier.
 */
final class AwaitingPlacement
{
    /** @return Collection<int, OrderItem> */
    public static function items(Order $order): Collection
    {
        /** @var Collection<int, OrderItem> $items */
        $items = $order->items()
            ->with(['fulfillmentJob', 'secret', 'productVariant.product'])
            ->orderBy('id')
            ->get()
            ->filter(static fn (OrderItem $item): bool => ! $item->service_type->isManual()
                && $item->fulfillmentJob === null
                && ! in_array($item->status, [
                    OrderItemStatus::Completed,
                    OrderItemStatus::Cancelled,
                    OrderItemStatus::Refunded,
                ], true))
            ->values();

        return $items;
    }
}
