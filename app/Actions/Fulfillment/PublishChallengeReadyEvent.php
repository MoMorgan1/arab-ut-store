<?php

namespace App\Actions\Fulfillment;

use App\Exceptions\PlacementRequestIncomplete;
use App\Fulfillment\Outbox\SignedOutboxDelivery;
use App\Models\IntegrationEvent;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Log;

/**
 * Sends one funded challenge's solve request to n8n (`solve-challenge`).
 *
 * The outbox row names the order item; the wire body adds the `item` block
 * `ComposePlacementRequest::challenge()` builds at this moment - the set,
 * the funding placement and the EA account as it stands now - which is the
 * whole reason the solve is its own workflow (owner decision, 2026-09-13).
 */
final class PublishChallengeReadyEvent
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

        if (! $this->delivery->claim($event, 'challenge.ready')) {
            return $event->fresh()?->status === 'processed';
        }

        $event->refresh();

        $item = OrderItem::query()
            ->with(['order.user', 'fulfillmentJob.placements', 'secret', 'productVariant.product'])
            ->where('public_id', $event->aggregate_id)
            ->first();

        if (! $item instanceof OrderItem) {
            $this->delivery->release($event, 'item_missing');

            return false;
        }

        // The solve was reported meanwhile - staff pasted the reference, or a
        // retry of this very send landed before the acknowledgement did.
        if ($item->fulfillmentJob?->placements->firstWhere('delivery_phase', 'challenge') !== null) {
            $this->delivery->markProcessed($event);

            return true;
        }

        try {
            $block = $this->compose->challenge($item, now());
        } catch (PlacementRequestIncomplete $incomplete) {
            Log::warning('Challenge request could not be composed; the event will be retried.', [
                'event_id' => $event->event_id,
                'order_number' => $item->order->order_number,
                'reason' => $incomplete->reason,
                'detail' => $incomplete->getMessage(),
            ]);
            $this->delivery->release($event, $incomplete->reason);

            return false;
        }

        $acknowledged = $this->delivery->deliver($event, 'solve_challenge', 1, [
            ...$event->payload,
            'customer_name' => (string) $item->order->user->name,
            'item' => $block,
        ]);

        if (! $acknowledged) {
            $this->delivery->release($event, 'delivery_failed');

            return false;
        }

        $this->delivery->markProcessed($event);

        return true;
    }
}
