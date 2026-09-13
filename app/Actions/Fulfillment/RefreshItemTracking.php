<?php

namespace App\Actions\Fulfillment;

use App\Account\Presenters\ItemTracking;
use App\Enums\ObservationResult;
use App\Enums\OrderStatus;
use App\Models\FulfillmentJob;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;

final class RefreshItemTracking
{
    public function __construct(
        private readonly ObserveFulfillmentJob $observe,
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

        $outcome = $this->observe->execute($job, $item, $order);

        // Rule 5: Stamp attention on a successful supplier read. A refresh press proves
        // a user is actively watching this order, which scheduled sweeps prioritize for
        // faster polling cadences. The sweep deliberately does not stamp, so only this
        // human path reaches here.
        if ($outcome->result === ObservationResult::Observed) {
            $job->forceFill(['last_viewed_at' => CarbonImmutable::now()])->save();
        }

        $item->refresh()->load('fulfillmentJob');

        return ItemTracking::for($item, $locale);
    }
}
