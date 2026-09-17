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
 *
 * **Scope: `order.paid` only.** `challenge.ready` releases its own rows with
 * their own reasons, keyed on the order ITEM rather than the order, and they
 * are not read here - not because they do not matter, but because there is no
 * alarm for them to grade. A funded challenge already carries a fulfillment
 * job for its coins phase, so the unplaced pass excludes it and the silence
 * pass only watches its failure counter. Grading a reason onto an alarm that
 * is never raised would be worse than the gap, because the table would then
 * look as though it covered the case. `docs/operations/fulfillment-recovery.md`
 * says which reasons reach an alarm and which do not.
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
     * nothing.
     *
     * @var list<string>
     */
    public const CLEARS_ITSELF = ['delivery_failed', 'order_missing'];

    /**
     * The reasons somebody has looked at and decided will not clear.
     *
     * Not consulted at runtime, and that is deliberate: an unrecognised reason
     * blocks, because this decides when an operator is told and a reason nobody
     * has classified yet is far better read out loud than sat on. What this
     * list is for is the decision itself. Adding a reason to
     * `ComposePlacementRequest` and shipping it would otherwise page Mohamed
     * five minutes after a paid order arrives, with nobody having judged
     * whether that is right; the test that walks every reason that class can
     * throw and demands it appear in one of these two lists is what makes the
     * judgement happen before the mail does.
     *
     * `max_attempts_exceeded` is here for a reason of its own. It means the
     * outbox gave up: the row is `failed`, and the publisher's selection takes
     * only `pending` rows, so nothing will ever retry it without
     * `orders:requeue-paid-event`. That is the plainest "will not fix itself"
     * in the list, whatever the row does or does not say about the cause.
     *
     * @var list<string>
     */
    public const BLOCKS = [
        'budget_unavailable',
        'challenge_unknown',
        'configuration_incomplete',
        'credentials_incomplete',
        'credentials_missing',
        'credentials_purged',
        'funding_missing',
        'max_attempts_exceeded',
        'platform_unsupported',
        'service_has_no_challenge',
    ];

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
