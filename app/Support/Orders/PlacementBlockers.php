<?php

namespace App\Support\Orders;

use App\Models\IntegrationEvent;

/**
 * Why the store has not sent a paid order to a supplier, and whether waiting
 * will help.
 *
 * The reason is already written down: every publisher release stamps
 * `integration_events.last_error` with the reason `PlacementRequestIncomplete`
 * carried, and `SignedOutboxDelivery::markProcessed()` clears it again on the
 * run that finally lands. One outbox row per order - the idempotency key is
 * `order-paid:<id>` - so the reason reads as one value per order, which is the
 * right grain: composition fails the whole request rather than one item of it.
 *
 * What this adds is the judgement the alarm needs. A paid order the store
 * cannot compose a request for is not the same emergency as a paid order n8n
 * has not acknowledged yet, and the difference decides how long the customer
 * waits before anybody hears about it.
 */
final class PlacementBlockers
{
    /**
     * The reasons another attempt can genuinely clear.
     *
     * Deliberately the short list rather than the long one. `delivery_failed`
     * is n8n not answering and `order_missing` is a row that raced the order
     * it names; both are the outbox working. Every other reason the publisher
     * can record is the store refusing to compose the request - no applied
     * pricing run to budget against, a platform no supplier serves, an EA
     * account that was purged - and refusing it again in a minute changes
     * nothing. An unrecognised reason is treated as blocking for the same
     * cause: this decides when an operator is told, and a new reason nobody
     * has classified yet is far better read out loud than sat on.
     *
     * @var list<string>
     */
    private const CLEARS_ITSELF = ['delivery_failed', 'order_missing'];

    /**
     * The stored reason per order, for the orders asked about.
     *
     * @param  list<string>  $orderPublicIds
     * @return array<string, string>
     */
    public static function reasons(array $orderPublicIds): array
    {
        if ($orderPublicIds === []) {
            return [];
        }

        /** @var array<string, string> $reasons */
        $reasons = IntegrationEvent::query()
            ->where('event_type', 'order.paid')
            ->whereIn('aggregate_id', $orderPublicIds)
            // A delivered event has had its reason cleared, so a row with one
            // is by definition a row still owing a placement.
            ->whereNotNull('last_error')
            ->orderBy('id')
            ->pluck('last_error', 'aggregate_id')
            ->all();

        return $reasons;
    }

    /** Whether this reason is one that retrying cannot clear. */
    public static function blocks(?string $reason): bool
    {
        return $reason !== null
            && $reason !== ''
            && ! in_array($reason, self::CLEARS_ITSELF, true);
    }
}
