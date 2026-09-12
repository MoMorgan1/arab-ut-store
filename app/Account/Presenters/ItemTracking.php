<?php

namespace App\Account\Presenters;

use App\Enums\ChallengeState;
use App\Enums\DeliveryPhase;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Enums\SupplierAction;
use App\Enums\TrackingPresentation;
use App\Models\FulfillmentJob;
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
     *         coinsUsed: int|null,
     *         finishedAt: string|null,
     *         actions: list<string>,
     *     }>|null,
     *     coverage: array{
     *         answered: int,
     *         requested: int,
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

        $isChallenge = $item->service_type === ServiceType::Sbc || $job->delivery_phase === DeliveryPhase::Challenge;
        $kind = $isChallenge ? 'challenge' : 'coins';

        $holdReason = $job->hold_reason;
        $observedAt = $job->observed_at;

        $presentation = $job->presentation ?? TrackingPresentation::NotReported;
        $holdTone = $job->hold_tone;

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
                    $challengeActions = array_map(
                        fn (SupplierAction $a): string => $a->value,
                        SupplierStateTranslator::sbcChallengeActions($job->supplier, $rawStatus),
                    );

                    $finTs = $entry['finishedAt'] ?? null;
                    $challengeFinishedAt = null;
                    if (is_numeric($finTs)) {
                        $ts = (int) $finTs;
                        $challengeFinishedAt = CarbonImmutable::createFromTimestamp($ts > 10000000000 ? (int) ($ts / 1000) : $ts)->utc()->toIso8601String();
                    }

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
            'actions' => array_map(
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
     *         coinsUsed: int|null,
     *         finishedAt: string|null,
     *         actions: list<string>,
     *     }>|null,
     *     coverage: array{
     *         answered: int,
     *         requested: int,
     *     }|null,
     * }|null
     */
    public function __invoke(OrderItem $item, string $locale): ?array
    {
        return self::for($item, $locale);
    }
}
