<?php

namespace App\Account\Presenters;

use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Models\FulfillmentJob;
use App\Models\OrderItem;
use Carbon\CarbonInterface;

/**
 * The customer-facing delivery progress for an order item, built strictly from
 * stored fulfillment job state without issuing synchronous supplier calls.
 */
final class ItemTracking
{
    /**
     * The customer-facing tracking payload from the last supplier observation.
     *
     * Returns null for manual-fulfillment items or items not yet placed with a
     * supplier. Supplier-internal diagnosis fields (observed_state, observation)
     * are strictly omitted so supplier vocabulary never leaks to the customer.
     *
     * @return array{
     *     supplier: string|null,
     *     phase: string|null,
     *     holdReason: string|null,
     *     holdMessage: string|null,
     *     actions: list<string>,
     *     supported: bool,
     *     observedAt: string|null,
     *     progress: array{
     *         coinsDelivered: int|null,
     *         coinsOrdered: int|null,
     *         challengesSolved: int|null,
     *         challengesRequested: int|null,
     *     }|null,
     * }|null
     */
    public static function for(OrderItem $item, string $locale): ?array
    {
        // Manual services (Objectives, Rivals, FUT Champions) are delivered by
        // human boosters and carry no automated supplier job to track.
        if ($item->service_type->isManual()) {
            return null;
        }

        $job = $item->fulfillmentJob;

        // An item not yet placed with a supplier carries nothing to track. That is
        // true whether the job row is absent or present-but-unbound: a job created
        // ahead of placement has no supplier reference yet, and reporting it as an
        // object of nulls would make "not placed" indistinguishable from "placed and
        // silent" without inspecting every field.
        if (! $job instanceof FulfillmentJob
            || $job->supplier === null
            || ($job->supplier_order_id ?? '') === '') {
            return null;
        }

        $holdReason = $job->hold_reason;
        $observedAt = $job->observed_at;

        // Progress is null when all counters are empty to prevent rendering
        // misleading "0 of 0" counters before the supplier reports numbers.
        $hasProgress = $job->coins_delivered !== null
            || $job->coins_ordered !== null
            || $job->challenges_solved !== null
            || $job->challenges_requested !== null;

        $progress = $hasProgress ? [
            'coinsDelivered' => $job->coins_delivered !== null ? (int) $job->coins_delivered : null,
            'coinsOrdered' => $job->coins_ordered !== null ? (int) $job->coins_ordered : null,
            'challengesSolved' => $job->challenges_solved !== null ? (int) $job->challenges_solved : null,
            'challengesRequested' => $job->challenges_requested !== null ? (int) $job->challenges_requested : null,
        ] : null;

        return [
            'supplier' => $job->supplier->value,
            'phase' => $job->delivery_phase?->value,
            'holdReason' => $holdReason?->value,
            'holdMessage' => $holdReason?->message($locale),
            // Actions is always a list (never null) so the client has a single type to handle.
            'actions' => array_map(
                fn (SupplierAction $action): string => $action->value,
                $job->allowedActions(),
            ),
            'supported' => (bool) $job->observation_supported,
            // Send the raw ISO timestamp so client-side timers calculate relative age accurately.
            'observedAt' => $observedAt instanceof CarbonInterface
                ? $observedAt->utc()->toIso8601String()
                : null,
            'progress' => $progress,
        ];
    }

    /**
     * Optional invokable wrapper for callers that resolve presenters via the container.
     *
     * @return array{
     *     supplier: string|null,
     *     phase: string|null,
     *     holdReason: string|null,
     *     holdMessage: string|null,
     *     actions: list<string>,
     *     supported: bool,
     *     observedAt: string|null,
     *     progress: array{
     *         coinsDelivered: int|null,
     *         coinsOrdered: int|null,
     *         challengesSolved: int|null,
     *         challengesRequested: int|null,
     *     }|null,
     * }|null
     */
    public function __invoke(OrderItem $item, string $locale): ?array
    {
        return self::for($item, $locale);
    }
}
