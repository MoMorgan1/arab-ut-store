<?php

namespace App\Actions\Fulfillment;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Asks the supplier about every automated item on one order, once, as the page
 * opens.
 *
 * Owner's decision: "opening the page is the refresh". There is no refresh
 * button anywhere on the screen, so this is the only moment at which a customer
 * can cause us to ask the supplier anything — and it is the moment they most
 * want it, because they opened the page to find out whether something changed.
 *
 * The cost is one supplier request per automated item, on the polling profile
 * (3s connect, 5s total). An order with several coins lines could therefore
 * hold the render open for longer than anyone would wait, so the loop carries
 * its own wall-clock deadline: items it does not reach keep the state already
 * stored, which the screen labels with its age anyway. The per-job lock inside
 * RefreshItemTracking is what keeps a reload storm down to one call in flight.
 */
final class RefreshOrderTracking
{
    /**
     * Long enough for two ordinary supplier reads, short enough that a page
     * behind a slow supplier still arrives. The page is not empty without it.
     */
    private const BUDGET_SECONDS = 6.0;

    public function __construct(
        private readonly RefreshItemTracking $refreshItem,
    ) {}

    public function execute(Order $order, string $locale): void
    {
        // A terminal order cannot change, and RefreshItemTracking would refuse
        // each item in turn — this just avoids loading them to find that out.
        if (in_array($order->status, [
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::Refunded,
        ], true)) {
            return;
        }

        $items = $order->relationLoaded('items')
            ? $order->items
            : $order->items()->with('fulfillmentJob.placements')->get();

        $startedAt = CarbonImmutable::now();

        foreach ($items as $item) {
            if ($item->service_type->isManual()) {
                continue;
            }

            if (CarbonImmutable::now()->diffInMilliseconds($startedAt, true) / 1000 >= self::BUDGET_SECONDS) {
                return;
            }

            // The presenter inside the refresh reads the order to decide whether
            // a finished one still offers actions; hand it the row we have.
            $item->setRelation('order', $order);

            $this->refreshItem->execute($item, $locale);
        }
    }
}
