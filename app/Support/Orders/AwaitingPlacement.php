<?php

namespace App\Support\Orders;

use App\Enums\OrderItemStatus;
use App\Enums\ServiceType;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * The silence alarm reads the same definition through `stale()`, because an
 * alarm that disagreed with the publisher about what is owed would either
 * wake Mohamed for items nobody meant to place or stay quiet about the ones
 * that vanished.
 */
final class AwaitingPlacement
{
    /**
     * The statuses that take an item out of a supplier's hands for good.
     *
     * Public because the alarm sweep asks the same question about items that
     * were placed, where this class's queries cannot help it: an alarm for an
     * item nobody owes anything on is an alarm that never closes.
     *
     * @var list<OrderItemStatus>
     */
    public const CLOSED = [
        OrderItemStatus::Completed,
        OrderItemStatus::Cancelled,
        OrderItemStatus::Refunded,
    ];

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
                && ! in_array($item->status, self::CLOSED, true))
            ->values();

        return $items;
    }

    /**
     * The same items across the whole store, on orders paid before a cutoff.
     *
     * The predicate above, in SQL: a sweep cannot load every paid order to
     * filter it in PHP. Both read `ServiceType::manual()` and `self::CLOSED`,
     * so the two spellings cannot drift apart on what counts as automated or
     * as finished.
     *
     * `$paidAfter` bounds the other end for a caller that only wants recent
     * orders - the alarm sweep opens a new alarm inside a window, but keeps
     * one already open however old it gets, so it asks this twice.
     *
     * @return Collection<int, OrderItem>
     */
    public static function stale(CarbonImmutable $paidBefore, ?CarbonImmutable $paidAfter = null): Collection
    {
        /** @var Collection<int, OrderItem> $items */
        $items = OrderItem::query()
            ->whereNotIn('service_type', array_map(
                static fn (ServiceType $service): string => $service->value,
                ServiceType::manual(),
            ))
            ->whereNotIn('status', array_map(
                static fn (OrderItemStatus $status): string => $status->value,
                self::CLOSED,
            ))
            ->whereDoesntHave('fulfillmentJob')
            // Paid, and long enough ago that every ordinary retry has had its
            // turn. An unpaid order owes a supplier nothing.
            ->whereHas('order', static fn (Builder $query) => $query
                ->whereNotNull('paid_at')
                ->where('paid_at', '<=', $paidBefore)
                ->when($paidAfter !== null, static fn (Builder $query) => $query
                    ->where('paid_at', '>=', $paidAfter)))
            ->with('order')
            ->orderBy('id')
            ->get();

        return $items;
    }
}
