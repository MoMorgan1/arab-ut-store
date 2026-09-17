<?php

namespace App\Actions\Fulfillment;

use App\Enums\DeliveryPhase;
use App\Enums\ObservationResult;
use App\Enums\OrderStatus;
use App\Enums\Supplier;
use App\Fulfillment\ChallengeAutoRetry;
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

/**
 * Reads one fulfillment job's supplier and applies the observation.
 *
 * This is the read half of {@see RefreshItemTracking}, lifted out so the
 * scheduled sweep can share it without the two things the refresh does on top:
 * stamping `last_viewed_at` (a human is watching) and building the presenter
 * payload. The sweep must not do either: a background read that stamped the
 * job as recently viewed would keep the fast polling band from ever draining,
 * and the payload is dead weight for a loop that renders nothing.
 *
 * The per-job cache lock is acquired here rather than by either caller, so a
 * customer refresh and a sweep tick reading the same job share one lock and
 * cannot issue the same supplier read twice in flight.
 */
final class ObserveFulfillmentJob
{
    public function __construct(
        private readonly SupplierRegistry $registry,
        private readonly SupplierStateTranslator $translator,
        private readonly ApplySupplierObservation $applyObservation,
        private readonly ChallengeAutoRetry $autoRetry,
    ) {}

    public function execute(FulfillmentJob $job, OrderItem $item, ?Order $order): ObservationOutcome
    {
        // Rule 1: Atomic cache lock keyed on the fulfillment job. A 10-second hold
        // safely exceeds the 5-second polling timeout profile while bounding orphan
        // holds. get() returns false immediately if already held: a concurrent
        // reader gets Busy and the caller decides what to do about it.
        $lock = Cache::lock("tracking-refresh:job:{$job->id}", 10);

        if (! $lock->get()) {
            return new ObservationOutcome(ObservationResult::Busy, null, 0.0);
        }

        try {
            return $this->observe($job, $item, $order);
        } finally {
            $lock->release();
        }
    }

    private function observe(FulfillmentJob $job, OrderItem $item, ?Order $order): ObservationOutcome
    {
        /** @var FulfillmentPlacement|null $placement */
        $placement = $job->relationLoaded('placements')
            ? $job->placements->first(fn (FulfillmentPlacement $p): bool => $p->delivery_phase === DeliveryPhase::Challenge)
            : $job->placements()->where('delivery_phase', DeliveryPhase::Challenge->value)->latest('id')->first();

        $challengeSupplier = $placement->supplier ?? $job->supplier;

        if ($job->delivery_phase === DeliveryPhase::Challenge
            && $challengeSupplier instanceof Supplier
            && $challengeSupplier->handlesChallenges()
        ) {
            return $this->observeChallenges($job, $item, $order, $placement, $challengeSupplier);
        }

        return $this->observePlain($job, $order);
    }

    private function observeChallenges(
        FulfillmentJob $job,
        OrderItem $item,
        ?Order $order,
        ?FulfillmentPlacement $placement,
        Supplier $challengeSupplier,
    ): ObservationOutcome {
        $challengeIds = $placement?->challengeIds() ?? [];

        if ($challengeIds === []) {
            Log::warning('No challenge IDs found for challenge placement on fulfillment job {job_id}', [
                'job_id' => $job->id,
                'order_item_id' => $item->id,
            ]);

            return new ObservationOutcome(ObservationResult::Unreadable, null, 0.0);
        }

        $started = microtime(true);

        try {
            $client = $this->registry->for($challengeSupplier);
            $bulk = $client->observeChallenges($challengeIds);
        } catch (SupplierUnavailable $exception) {
            return new ObservationOutcome(ObservationResult::Unavailable, $exception->reason, $this->elapsed($started));
        } catch (SupplierNotConfigured $exception) {
            // A missing key is ours to fix, not the customer's to see.
            Log::error('Supplier not configured while reading challenges for fulfillment job {job_id}', [
                'job_id' => $job->id,
                'supplier' => $challengeSupplier->value,
                'reason' => $exception->reason,
            ]);

            return new ObservationOutcome(ObservationResult::NotConfigured, $exception->reason, $this->elapsed($started));
        }

        $latencyMs = $this->elapsed($started);

        // A response naming none of our ids told us nothing. The comparison lives in
        // the translator so there is one implementation of it, not two that can drift.
        if (! $this->translator->challengeResponseAnswersRequest($challengeIds, $bulk)) {
            Log::warning('Bulk challenge response contained no requested challenge IDs for fulfillment job {job_id}', [
                'job_id' => $job->id,
                'challenge_ids' => $challengeIds,
            ]);

            return new ObservationOutcome(ObservationResult::Unreadable, null, $latencyMs);
        }

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
            // The challenge placement's supplier, which is not always the
            // job's: the job row advertises the first placement, and a job can
            // fund its coins at one supplier and solve at another.
            $challengeSupplier,
        );

        // v14's automatic retrySBCAPI on a transient solve status, on its
        // cadence (owner decision, 2026-09-15). After the observation is
        // written, so what the customer sees is this read, not the retry.
        $this->autoRetry->execute($job, $placement, $bulk, $client);

        return new ObservationOutcome(ObservationResult::Observed, null, $latencyMs);
    }

    private function observePlain(FulfillmentJob $job, ?Order $order): ObservationOutcome
    {
        $supplier = $job->supplier;

        if (! $supplier instanceof Supplier) {
            // Both callers refuse a job without a supplier before they reach here
            // (RefreshItemTracking's refuse check, the sweep's selection), so this
            // arm is unreachable in practice and exists only for the type.
            return new ObservationOutcome(ObservationResult::NotConfigured, 'missing_supplier', 0.0);
        }

        $started = microtime(true);

        try {
            // SupplierClient::observe() inherently uses SupplierCallProfile::Polling.
            $client = $this->registry->for($supplier);
            $observation = $client->observe((string) $job->supplier_order_id);
        } catch (SupplierUnavailable $exception) {
            return new ObservationOutcome(ObservationResult::Unavailable, $exception->reason, $this->elapsed($started));
        } catch (SupplierNotConfigured $exception) {
            Log::error('Supplier not configured while reading fulfillment job {job_id}', [
                'job_id' => $job->id,
                'supplier' => $supplier->value,
                'reason' => $exception->reason,
            ]);

            return new ObservationOutcome(ObservationResult::NotConfigured, $exception->reason, $this->elapsed($started));
        }

        $latencyMs = $this->elapsed($started);

        // Rule: Never write canonical state directly. Translate into domain state and
        // delegate to ApplySupplierObservation for transactional reconciliation.
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
            $supplier,
        );

        return new ObservationOutcome(ObservationResult::Observed, null, $latencyMs);
    }

    private function elapsed(float $started): float
    {
        return (microtime(true) - $started) * 1000;
    }
}
