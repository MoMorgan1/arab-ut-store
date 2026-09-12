<?php

namespace App\Actions\Fulfillment;

use App\Account\Presenters\ItemTracking;
use App\Enums\OrderStatus;
use App\Models\FulfillmentJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Suppliers\Exceptions\SupplierUnavailable;
use App\Suppliers\SupplierRegistry;
use App\Suppliers\Translation\SupplierStateTranslator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class RefreshItemTracking
{
    public function __construct(
        private readonly SupplierRegistry $registry,
        private readonly SupplierStateTranslator $translator,
        private readonly ApplySupplierObservation $applyObservation,
    ) {}

    /**
     * Refreshes the tracking state for an order item by querying its supplier,
     * translating the observation, and applying it to canonical storage.
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
    public function execute(OrderItem $item, string $locale): ?array
    {
        // Rule 2: Refuse pointless cases before touching the network or acquiring a lock.
        // Returning the stored tracking object without issuing an external supplier call.
        // 1) Manual services (Objectives, Rivals, FUT Champions) are delivered by human boosters; no supplier has them.
        if ($item->service_type->isManual()) {
            return ItemTracking::for($item, $locale);
        }

        $job = $item->relationLoaded('fulfillmentJob')
            ? $item->fulfillmentJob
            : $item->fulfillmentJob()->first();

        // 2) An automated item without a placed fulfillment job has nothing upstream to query.
        if (! $job instanceof FulfillmentJob
            || $job->supplier === null
            || empty($job->supplier_order_id)
        ) {
            return ItemTracking::for($item, $locale);
        }

        $order = $item->relationLoaded('order')
            ? $item->order
            : $item->order()->first();

        // 3) Terminal orders (Completed, Cancelled, Refunded) cannot change state, and
        // ApplySupplierObservation would discard/refuse writes anyway.
        if ($order instanceof Order && in_array($order->status, [
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::Refunded,
        ], true)) {
            return ItemTracking::for($item, $locale);
        }

        // Rule 1: Atomic cache lock keyed on the fulfillment job.
        // A 10-second hold safely exceeds the 5-second polling timeout profile while bounding orphan holds.
        // Cache::lock()->get() returns false immediately if already held. We do NOT block and wait:
        // a concurrent viewer gets the current stored tracking object back immediately while the in-flight
        // refresh updates storage for the next page load.
        $lock = Cache::lock("tracking-refresh:job:{$job->id}", 10);

        if (! $lock->get()) {
            return ItemTracking::for($item, $locale);
        }

        try {
            // Rule 3: Use the POLLING timeout profile (3s connect / 5s total), not the action profile (5s / 12s).
            // A customer pressing refresh already has a value on screen, so a slow supplier should give up quickly
            // rather than hold their request open for twelve seconds. The longer profile is for actions that must land.
            // SupplierClient::observe() inherently uses SupplierCallProfile::Polling.
            try {
                $client = $this->registry->for($job->supplier);
                $observation = $client->observe((string) $job->supplier_order_id);
            } catch (SupplierUnavailable) {
                // Rule 4: A supplier failure is not a 500. Return the stored tracking object unchanged
                // and let the page keep showing the old value with its age. Do not swallow anything else.
                return ItemTracking::for($item, $locale);
            }

            // Rule 5: Stamp attention on a successful supplier read. A refresh press proves a user is actively
            // watching this order, which scheduled sweeps prioritize for faster polling cadences.
            $job->forceFill(['last_viewed_at' => CarbonImmutable::now()])->save();

            // Rule 6: Never write canonical state directly. Translate into domain state and delegate to
            // ApplySupplierObservation for transactional reconciliation, status transitions, and invariants.
            $orderStatus = $order instanceof Order ? $order->status : OrderStatus::InProgress;
            $translated = $this->translator->translate(
                $observation,
                $orderStatus,
                $job->delivery_phase,
            );

            $this->applyObservation->execute(
                $job,
                $translated,
                $observation->fetchedAt,
                $observation->payload,
            );

            $item->refresh()->load('fulfillmentJob');

            return ItemTracking::for($item, $locale);
        } finally {
            $lock->release();
        }
    }
}
