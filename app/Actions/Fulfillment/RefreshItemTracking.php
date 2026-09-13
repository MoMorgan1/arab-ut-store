<?php

namespace App\Actions\Fulfillment;

use App\Account\Presenters\ItemTracking;
use App\Enums\DeliveryPhase;
use App\Enums\OrderStatus;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Suppliers\Exceptions\SupplierNotConfigured;
use App\Suppliers\Exceptions\SupplierUnavailable;
use App\Suppliers\SupplierRegistry;
use App\Suppliers\Translation\SupplierStateTranslator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
     *     kind: string,
     *     phase: string|null,
     *     presentation: string,
     *     headline: string,
     *     subline: string,
     *     holdReason: string|null,
     *     holdMessage: string|null,
     *     holdTone: string|null,
     *     completedAt: string|null,
     *     actions: list<string>,
     *     supported: bool,
     *     observedAt: string|null,
     *     accountCoins: array{
     *         amount: int|null,
     *         state: string,
     *     },
     *     progress: array{
     *         coinsDelivered: int|null,
     *         coinsOrdered: int|null,
     *         squadsDone: int|null,
     *         squadsTotal: int|null,
     *         solvesDone: int|null,
     *         solvesTotal: int|null,
     *     }|null,
     *     challenges: list<array{
     *         target: int,
     *         state: string,
     *         stateLabel: string,
     *         help: array{title: string, desc: string, action: string},
     *         squads: array{done: int|null, total: int|null},
     *         solves: array{done: int|null, total: int|null},
     *         holdReason: string|null,
     *         holdMessage: string|null,
     *         holdTone: string|null,
     *         coinsUsed: int|null,
     *         finishedAt: string|null,
     *         actions: list<string>,
     *     }>|null,
     *     coverage: array{
     *         answered: int,
     *         requested: int,
     *     }|null,
     *     workStarted: bool,
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

        // Hand the order back to the item so ItemTracking, which needs the same row to
        // decide whether a terminal order still offers actions, does not fetch it again.
        if ($order instanceof Order && ! $item->relationLoaded('order')) {
            $item->setRelation('order', $order);
        }

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
            /** @var FulfillmentPlacement|null $placement */
            $placement = $job->relationLoaded('placements')
                ? $job->placements->first(fn ($p) => $p->delivery_phase === DeliveryPhase::Challenge)
                : $job->placements()->where('delivery_phase', DeliveryPhase::Challenge->value)->latest('id')->first();

            $challengeSupplier = $placement->supplier ?? $job->supplier;

            if ($job->delivery_phase === DeliveryPhase::Challenge && $challengeSupplier->handlesChallenges()) {
                $challengeIds = $placement?->challengeIds() ?? [];

                if ($challengeIds === []) {
                    Log::warning('No challenge IDs found for challenge placement on fulfillment job {job_id}', [
                        'job_id' => $job->id,
                        'order_item_id' => $item->id,
                    ]);

                    return ItemTracking::for($item, $locale);
                }

                try {
                    $client = $this->registry->for($challengeSupplier);
                    $bulk = $client->observeChallenges($challengeIds);
                } catch (SupplierUnavailable) {
                    return ItemTracking::for($item, $locale);
                } catch (SupplierNotConfigured $exception) {
                    // A missing key is ours to fix, not the customer's to see. It is
                    // logged loudly and the stored state is returned, because the page
                    // that opened this read must still render.
                    Log::error('Supplier not configured while reading challenges for fulfillment job {job_id}', [
                        'job_id' => $job->id,
                        'supplier' => $challengeSupplier->value,
                        'reason' => $exception->reason,
                    ]);

                    return ItemTracking::for($item, $locale);
                }

                // A response naming none of our ids told us nothing. The comparison lives in
                // the translator so there is one implementation of it, not two that can drift.
                if (! $this->translator->challengeResponseAnswersRequest($challengeIds, $bulk)) {
                    Log::warning('Bulk challenge response contained no requested challenge IDs for fulfillment job {job_id}', [
                        'job_id' => $job->id,
                        'challenge_ids' => $challengeIds,
                    ]);

                    return ItemTracking::for($item, $locale);
                }

                $job->forceFill(['last_viewed_at' => CarbonImmutable::now()])->save();

                $orderStatus = $order instanceof Order ? $order->status : OrderStatus::InProgress;

                $translated = $this->translator->translateChallenge(
                    $challengeSupplier,
                    $challengeIds,
                    $bulk,
                    $orderStatus,
                );

                $this->applyObservation->execute(
                    $job,
                    $translated,
                    CarbonImmutable::now(),
                    $bulk,
                );

                $item->refresh()->load('fulfillmentJob');

                return ItemTracking::for($item, $locale);
            }

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
            } catch (SupplierNotConfigured $exception) {
                // Same answer for the same reason, and the same rule holds now that
                // opening the page is what triggers this read: a deployment with a
                // missing key must not turn a customer's order into an error page.
                Log::error('Supplier not configured while reading fulfillment job {job_id}', [
                    'job_id' => $job->id,
                    'supplier' => $job->supplier->value,
                    'reason' => $exception->reason,
                ]);

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
