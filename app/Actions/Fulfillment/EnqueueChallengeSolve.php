<?php

namespace App\Actions\Fulfillment;

use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\ServiceType;
use App\Models\FulfillmentJob;
use App\Models\IntegrationEvent;
use App\Models\OrderItem;
use Illuminate\Support\Str;

/**
 * Queues the solve for a challenge whose coins have landed.
 *
 * Owner decision, 2026-09-13: the challenge phase is its own workflow,
 * triggered by the store when the funding shipment completes, so the solve
 * starts from a fresh payload composed at send time (`ComposeChallengeRequest`)
 * and a corrected email reaches the supplier. Called inside the observation
 * transaction, after the job has been written, so the row commits with the
 * completion or not at all.
 *
 * Nothing is queued unless every one of these holds: the item is a
 * challenge, the job has just completed its coins phase, no challenge
 * placement exists yet (staff may have pasted one), and no row for this item
 * was queued before - a repeated "completed" reading must not queue twice.
 */
final class EnqueueChallengeSolve
{
    public function execute(OrderItem $item, FulfillmentJob $job): ?IntegrationEvent
    {
        if ($item->service_type !== ServiceType::Sbc
            || $item->status === OrderItemStatus::Cancelled
            || $item->status === OrderItemStatus::Refunded
            || $job->status !== FulfillmentStatus::Completed
            || $job->delivery_phase !== DeliveryPhase::Coins) {
            return null;
        }

        if ($job->placements()->where('delivery_phase', DeliveryPhase::Challenge->value)->exists()) {
            return null;
        }

        $idempotencyKey = 'challenge-ready:'.$item->id;

        if (IntegrationEvent::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return null;
        }

        $order = $item->order;

        return IntegrationEvent::create([
            'event_id' => (string) Str::ulid(),
            'event_type' => 'challenge.ready',
            'aggregate_type' => 'order_item',
            'aggregate_id' => $item->public_id,
            'schema_version' => 1,
            'payload' => [
                'order_public_id' => $order->public_id,
                'order_number' => $order->order_number,
                'order_item_public_id' => $item->public_id,
            ],
            'status' => 'pending',
            'idempotency_key' => $idempotencyKey,
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }
}
