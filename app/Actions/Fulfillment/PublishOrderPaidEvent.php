<?php

namespace App\Actions\Fulfillment;

use App\Exceptions\PlacementRequestIncomplete;
use App\Fulfillment\Outbox\SignedOutboxDelivery;
use App\Models\IntegrationEvent;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Sends one paid order's placement request to n8n.
 *
 * The stored outbox row names the order; the wire body adds the `items` block
 * `ComposePlacementRequest` builds at this moment - configuration, budget and
 * the EA account - so the request leaves with today's credentials and today's
 * supplier prices, and the table it came from never held either.
 */
final class PublishOrderPaidEvent
{
    public function __construct(
        private readonly ComposePlacementRequest $compose,
        private readonly SignedOutboxDelivery $delivery,
    ) {}

    public function execute(IntegrationEvent $event): bool
    {
        if ($event->fresh()?->status === 'processed') {
            return true;
        }

        if (! $this->delivery->claim($event, 'order.paid')) {
            return $event->fresh()?->status === 'processed';
        }

        $event->refresh();

        $order = Order::query()
            ->with('user')
            ->where('public_id', $event->aggregate_id)
            ->first();

        if (! $order instanceof Order) {
            $this->delivery->release($event, 'order_missing');

            return false;
        }

        try {
            $items = $this->compose->execute($order, now());
        } catch (PlacementRequestIncomplete $incomplete) {
            Log::warning('Placement request could not be composed; the event will be retried.', [
                'event_id' => $event->event_id,
                'order_number' => $order->order_number,
                'reason' => $incomplete->reason,
                'detail' => $incomplete->getMessage(),
            ]);
            $this->delivery->release($event, $incomplete->reason);

            return false;
        }

        // Every automated item gained a placement since the row was written -
        // staff pasted the references, or the items were cancelled. There is
        // nothing for n8n to place, and an empty request would only teach the
        // workflow to ignore requests.
        if ($items === []) {
            $this->delivery->markProcessed($event);

            return true;
        }

        $acknowledged = $this->delivery->deliver($event, 'order_paid', 2, [
            ...$event->payload,
            // Both suppliers file the account under a customer name;
            // v14 sent Salla's full name. Phone and email stay out.
            'customer_name' => (string) $order->user->name,
            'items' => $items,
        ]);

        if (! $acknowledged) {
            $this->delivery->release($event, 'delivery_failed');

            return false;
        }

        $this->delivery->markProcessed($event);

        return true;
    }
}
