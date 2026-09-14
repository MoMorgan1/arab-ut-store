<?php

namespace App\Actions\Fulfillment;

use App\Models\IntegrationEvent;
use App\Models\Order;
use App\Support\Orders\AwaitingPlacement;
use Illuminate\Support\Str;

/**
 * Queues the placement request for an order that has just been paid.
 *
 * Called inside the transaction that marks the order paid - wallet checkout,
 * the Paylink reconciliation, and a manual order - so the outbox row commits
 * with the payment or not at all. The row carries identifiers only; the
 * publisher composes what the suppliers need when it sends
 * (`ComposePlacementRequest`), which is what keeps this table free of EA
 * credentials (ADR 2026-09-12).
 *
 * No row is written when nothing awaits placement: an order of booster
 * services, or a manual order whose every automated item already carries a
 * pasted supplier reference. n8n has no work on those, and an event that
 * says so would only teach the workflow to ignore events.
 */
final class EnqueueOrderPlacement
{
    public function execute(Order $order): ?IntegrationEvent
    {
        if (AwaitingPlacement::items($order)->isEmpty()) {
            return null;
        }

        return IntegrationEvent::create([
            'event_id' => (string) Str::ulid(),
            'event_type' => 'order.paid',
            'aggregate_type' => 'order',
            'aggregate_id' => $order->public_id,
            'schema_version' => 2,
            'payload' => [
                'order_public_id' => $order->public_id,
                'order_number' => $order->order_number,
                'channel' => $order->channel,
                'locale' => $order->locale,
                'currency' => $order->currency,
                'total_halalah' => $order->total_halalah,
                'item_count' => $order->items()->count(),
            ],
            'status' => 'pending',
            'idempotency_key' => 'order-paid:'.$order->id,
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }
}
