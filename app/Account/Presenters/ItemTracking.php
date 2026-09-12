<?php

namespace App\Account\Presenters;

use App\Enums\ChallengeState;
use App\Enums\DeliveryPhase;
use App\Enums\HoldTone;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Enums\TrackingPresentation;
use App\Models\FulfillmentJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Suppliers\ChallengeIds;
use App\Suppliers\Translation\SupplierStateTranslator;
use Carbon\CarbonImmutable;
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
     *     kind: string,
     *     phase: string|null,
     *     presentation: string,
     *     headline: string,
     *     subline: string,
     *     // The completed subline carries a `:console` placeholder, because the
     *     // tracker names the customer's own console in it and the item already
     *     // carries the platform; the client substitutes it.
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

        $isChallenge = $item->service_type === ServiceType::Sbc || $job->delivery_phase === DeliveryPhase::Challenge;
        $kind = $isChallenge ? 'challenge' : 'coins';

        $holdReason = $job->hold_reason;
        $observedAt = $job->observed_at;

        $presentation = $job->presentation ?? TrackingPresentation::NotReported;
        $holdTone = $job->hold_tone;

        // Terminal is decided here and nowhere else.
        //
        // The job's stored fields are the last thing a supplier said, and nothing clears
        // them when an order ends: RefreshItemTracking refuses a terminal order before it
        // reaches the translator (RefreshItemTracking.php:109), the admin transition and
        // the refund never touch fulfillment_jobs, and the translator's own terminal
        // branches are therefore unreachable from production. So an order cancelled
        // mid-delivery still holds `transferring` and `edit_credentials`, and without this
        // the customer reads "we are moving your coins" under a cancelled order, beside a
        // button that does nothing.
        //
        // The item's status answers first because it is the one that leads: an order only
        // becomes Completed once every item is (ApplySupplierObservation.php:248), and the
        // cancel and refund paths update the items alongside the order. The order is the
        // safety net, read lazily because this method is a public entry point and cannot
        // assume a caller loaded the relation; both real callers set it first, so the query
        // is a fallback rather than a per-item cost on the order page.
        $order = $item->relationLoaded('order') ? $item->order : $item->order()->first();

        $terminalPresentation = match (true) {
            $item->status === OrderItemStatus::Cancelled => TrackingPresentation::Cancelled,
            $item->status === OrderItemStatus::Refunded => TrackingPresentation::Refunded,
            $item->status === OrderItemStatus::Completed => TrackingPresentation::Completed,
            ! $order instanceof Order => null,
            $order->status === OrderStatus::Cancelled => TrackingPresentation::Cancelled,
            $order->status === OrderStatus::Refunded => TrackingPresentation::Refunded,
            $order->status === OrderStatus::Completed => TrackingPresentation::Completed,
            default => null,
        };

        $orderIsTerminal = $terminalPresentation !== null;

        if ($terminalPresentation !== null) {
            // Nothing is pending on a finished order, so no hold and no buttons. The
            // counters, the balance and the completion time stay: they are facts about
            // what happened, not invitations to act.
            $presentation = $terminalPresentation;
            $holdReason = null;
            $holdTone = null;
        }

        $completedAt = $job->completed_at?->utc()->toIso8601String();
        if ($completedAt === null && isset($job->observation['finishedAt']) && is_numeric($job->observation['finishedAt'])) {
            $ts = (int) $job->observation['finishedAt'];
            $completedAt = CarbonImmutable::createFromTimestamp($ts > 10000000000 ? (int) ($ts / 1000) : $ts)->utc()->toIso8601String();
        }

        // Account coins: known balance, preparing (-1), or unknown
        $coinsCust = $job->observation['coinsCustomerAccount'] ?? null;
        if ($coinsCust !== null && is_numeric($coinsCust)) {
            $val = (int) $coinsCust;
            $accountCoins = $val === -1
                ? ['amount' => null, 'state' => 'preparing']
                : ['amount' => max(0, $val), 'state' => 'known'];
        } else {
            $accountCoins = ['amount' => null, 'state' => 'unknown'];
        }

        // Fallback coinsOrdered from item configuration when job is unobserved
        $coinsOrderedFallback = isset($item->configuration['coins_quantity']) && is_numeric($item->configuration['coins_quantity'])
            ? (int) $item->configuration['coins_quantity']
            : null;

        $coinsOrdered = $job->coins_ordered !== null
            ? (int) $job->coins_ordered
            : $coinsOrderedFallback;

        // Progress is null when all counters are empty to prevent rendering
        // misleading "0 of 0" counters before the supplier reports numbers.
        $hasProgress = $job->coins_delivered !== null
            || $coinsOrdered !== null
            || $job->squads_done !== null
            || $job->squads_total !== null
            || $job->solves_done !== null
            || $job->solves_total !== null;

        $progress = $hasProgress ? [
            'coinsDelivered' => $job->coins_delivered !== null ? (int) $job->coins_delivered : null,
            'coinsOrdered' => $coinsOrdered,
            'squadsDone' => $job->squads_done !== null ? (int) $job->squads_done : null,
            'squadsTotal' => $job->squads_total !== null ? (int) $job->squads_total : null,
            'solvesDone' => $job->solves_done !== null ? (int) $job->solves_done : null,
            'solvesTotal' => $job->solves_total !== null ? (int) $job->solves_total : null,
        ] : null;

        $challenges = null;
        $coverage = null;

        if ($kind === 'challenge') {
            $placement = $job->relationLoaded('placements')
                ? $job->placements->first(fn ($p) => $p->delivery_phase === DeliveryPhase::Challenge)
                : $job->placements()->where('delivery_phase', DeliveryPhase::Challenge->value)->first();

            $challengeSupplier = $placement->supplier ?? $job->supplier;

            $requestedIds = $placement?->challengeIds() ?? [];
            $rawObservation = is_array($job->observation) ? $job->observation : [];

            $challengesList = [];
            $answeredCount = 0;
            $target = 0;

            foreach ($requestedIds as $reqId) {
                $norm = ChallengeIds::normalize([$reqId])[0] ?? $reqId;
                $entry = $rawObservation[$norm] ?? null;

                if (is_array($entry)) {
                    $answeredCount++;
                    $rawStatus = is_string($entry['sbcStatus'] ?? null) ? trim($entry['sbcStatus']) : '';
                    $stateEnum = SupplierStateTranslator::challengeState($rawStatus);
                    $challengeActions = $orderIsTerminal ? [] : array_map(
                        fn (SupplierAction $a): string => $a->value,
                        SupplierStateTranslator::sbcChallengeActions($challengeSupplier, $rawStatus),
                    );

                    $finTs = $entry['finishedAt'] ?? null;
                    $challengeFinishedAt = null;
                    if (is_numeric($finTs)) {
                        $ts = (int) $finTs;
                        $challengeFinishedAt = CarbonImmutable::createFromTimestamp($ts > 10000000000 ? (int) ($ts / 1000) : $ts)->utc()->toIso8601String();
                    }

                    // A finished order asks nothing of anyone, and that has to reach the
                    // cards too: emptying their buttons while leaving "fix your sign-in
                    // details" above them is the same defect one level down.
                    $cardHoldReason = $orderIsTerminal
                        ? null
                        : SupplierStateTranslator::SBC_STATUS_HOLDS[$rawStatus] ?? null;
                    $cardHoldMessage = $cardHoldReason?->message($locale);
                    $cardHoldTone = match (true) {
                        $cardHoldReason === null => null,
                        in_array($rawStatus, SupplierStateTranslator::SBC_SYSTEM_INFO_STATUSES, true) => HoldTone::Info->value,
                        default => HoldTone::Action->value,
                    };

                    $challengesList[] = [
                        'target' => $target,
                        'state' => $stateEnum->value,
                        'stateLabel' => $stateEnum->label($locale),
                        'squads' => [
                            'done' => isset($entry['challengesDone']) && is_numeric($entry['challengesDone']) ? (int) $entry['challengesDone'] : null,
                            'total' => isset($entry['totalChallenges']) && is_numeric($entry['totalChallenges']) ? (int) $entry['totalChallenges'] : null,
                        ],
                        'solves' => [
                            'done' => isset($entry['timesSolved']) && is_numeric($entry['timesSolved']) ? (int) $entry['timesSolved'] : null,
                            'total' => isset($entry['timesToSolve']) && is_numeric($entry['timesToSolve']) ? (int) $entry['timesToSolve'] : null,
                        ],
                        'holdReason' => $cardHoldReason?->value,
                        'holdMessage' => $cardHoldMessage,
                        'holdTone' => $cardHoldTone,
                        'coinsUsed' => isset($entry['costCoins']) && is_numeric($entry['costCoins']) ? (int) $entry['costCoins'] : null,
                        'finishedAt' => $challengeFinishedAt,
                        'actions' => $challengeActions,
                    ];
                } else {
                    $challengesList[] = [
                        'target' => $target,
                        'state' => ChallengeState::Unknown->value,
                        'stateLabel' => ChallengeState::Unknown->label($locale),
                        'squads' => ['done' => null, 'total' => null],
                        'solves' => ['done' => null, 'total' => null],
                        'holdReason' => null,
                        'holdMessage' => null,
                        'holdTone' => null,
                        'coinsUsed' => null,
                        'finishedAt' => null,
                        'actions' => [],
                    ];
                }

                $target++;
            }

            $challenges = $challengesList;
            $coverage = [
                'answered' => $answeredCount,
                'requested' => count($requestedIds),
            ];
        }

        // Computed dynamically from the stored observation without a schema migration.
        // Indicates whether work on the order has visibly begun, enabling the client to
        // accurately evaluate whether to clear its optimistic retry grace window.
        // TrackingPresentation cannot stand in for this because presentation can be
        // suppressed by cooldowns or customer-action messages while work has visibly begun.
        $obs = is_array($job->observation) ? $job->observation : [];
        $rawStatus = is_string($obs['status'] ?? null) ? strtolower(trim($obs['status'])) : '';
        $accCheck = is_string($obs['accountCheck'] ?? null) ? trim($obs['accountCheck']) : '';
        $econ = is_string($obs['economyState'] ?? null) ? trim($obs['economyState']) : '';
        $simplified = is_string($obs['simplifiedStatus'] ?? null) ? strtolower(trim($obs['simplifiedStatus'])) : '';
        // The translator's exact check, not the tracker's substring one: 'finish'
        // also matches 'unfinished', which this store pins as not finished.
        $isFinished = SupplierStateTranslator::statusIsFinished($rawStatus)
            || $job->completed_at !== null;

        $workStarted = $isFinished
            || in_array($accCheck, ['entered', 'started', 'userPassVerified', 'correctBA'], true)
            || in_array($econ, ['transfersInProgress', 'transferCycleComplete', 'customerHasPlayer', 'customerListedPlayer'], true)
            || ($rawStatus === 'transfersinprogress' && $simplified !== 'error');

        return [
            'kind' => $kind,
            'phase' => $job->delivery_phase?->value,
            'presentation' => $presentation->value,
            'headline' => $presentation->headline($locale),
            'subline' => $presentation->subline($locale),
            'holdReason' => $holdReason?->value,
            'holdMessage' => $holdReason?->message($locale),
            'holdTone' => $holdTone?->value,
            'completedAt' => $completedAt,
            // Actions is always a list (never null) so the client has a single type to handle.
            'actions' => $orderIsTerminal ? [] : array_map(
                fn (SupplierAction $action): string => $action->value,
                $job->allowedActions(),
            ),
            'supported' => (bool) $job->observation_supported,
            // Send the raw ISO timestamp so client-side timers calculate relative age accurately.
            'observedAt' => $observedAt instanceof CarbonInterface
                ? $observedAt->utc()->toIso8601String()
                : null,
            'accountCoins' => $accountCoins,
            'progress' => $progress,
            'challenges' => $challenges,
            'coverage' => $coverage,
            'workStarted' => $workStarted,
        ];
    }

    /**
     * Optional invokable wrapper for callers that resolve presenters via the container.
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
    public function __invoke(OrderItem $item, string $locale): ?array
    {
        return self::for($item, $locale);
    }
}
