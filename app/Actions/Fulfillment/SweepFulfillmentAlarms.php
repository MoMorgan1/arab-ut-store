<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentAlarmKind;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\FulfillmentAlarm;
use App\Models\FulfillmentJob;
use App\Models\OrderItem;
use App\Support\Orders\AwaitingPlacement;
use App\Support\Orders\PlacementBlockers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Brings the alarm table level with what is actually silent right now.
 *
 * Two silences, neither of which anything else can report. An automated item
 * that was paid for and never placed is invisible to n8n - from its side the
 * placement succeeded and the callback was lost - and invisible to the poller,
 * which only reads jobs that already carry a supplier reference. A placed item
 * whose reads keep coming back empty is visible to the poller, which dutifully
 * backs off and retries it forever without ever saying so out loud.
 *
 * The first of those waits a length of time that depends on why it is stuck:
 * a request the store is refusing to compose will read the same tomorrow, so
 * it is said out loud long before one that is merely mid-retry.
 *
 * Raising and resolving are one pass, because an alarm panel that adds today's
 * silence before removing yesterday's shows a number that was never true.
 */
final class SweepFulfillmentAlarms
{
    /**
     * @return array{raised: int, resolved: int, open: int}
     */
    public function execute(): array
    {
        $now = CarbonImmutable::now();

        $unplaced = $this->unplaced($now);
        $silent = $this->silent();

        $raised = $this->raise(FulfillmentAlarmKind::Unplaced, $this->raisable($unplaced), $now)
            + $this->raise(FulfillmentAlarmKind::Silent, $silent, $now);

        // Resolution reads the unwindowed sets on purpose. An alarm is closed
        // when its condition is gone, never because the item aged out of the
        // window that was allowed to raise it - an order that stayed lost for
        // three days is still lost.
        $resolved = $this->resolve(FulfillmentAlarmKind::Unplaced, array_keys($unplaced), $now)
            + $this->resolve(FulfillmentAlarmKind::Silent, array_keys($silent), $now);

        return [
            'raised' => $raised,
            'resolved' => $resolved,
            'open' => FulfillmentAlarm::query()->whereNull('resolved_at')->count(),
        ];
    }

    /**
     * Every paid automated item no supplier was ever asked to deliver, past
     * the wait its own reason has earned.
     *
     * The wait is graded rather than flat, because "not placed yet" covers two
     * different days. An order n8n has simply not acknowledged is mid-retry and
     * gets the full quarter hour. An order the store is refusing to compose a
     * request for - a PC challenge on a pricing run that carries no PC cost
     * tiers, an EA account already purged - will read exactly the same in a
     * fortnight, so it is said out loud almost at once: nothing in the system
     * is going to fix it, and the customer is waiting on a person.
     *
     * @return array<int, array{context: array<string, mixed>, raisable: bool}>
     */
    private function unplaced(CarbonImmutable $now): array
    {
        $patient = $now->subMinutes($this->unplacedAfterMinutes());
        $blocked = $now->subMinutes($this->blockedAfterMinutes());
        // The shorter of the two waits selects the superset; each item is then
        // held to the one its own reason earns, below.
        $items = AwaitingPlacement::stale($patient->greaterThan($blocked) ? $patient : $blocked);

        $reasons = PlacementBlockers::reasons(array_values(array_unique(
            $items->map(static fn (OrderItem $item): string => (string) $item->order->public_id)->all(),
        )));

        $windowOpensAt = $now->subHours($this->raiseWindowHours());
        $found = [];

        foreach ($items as $item) {
            $paidAt = $item->order->paid_at;

            // `AwaitingPlacement::stale()` already requires a paid order, so
            // this is the type talking rather than a case.
            if ($paidAt === null) {
                continue;
            }

            $reason = $reasons[(string) $item->order->public_id] ?? null;
            $isBlocked = PlacementBlockers::blocks($reason);

            if ($paidAt->greaterThan($isBlocked ? $blocked : $patient)) {
                continue;
            }

            $context = [
                'order_number' => (string) $item->order->order_number,
                // The item, not just its order. Several items can share one
                // order and one reason - the reason is stored per order - and
                // for a per-item cause like a purged EA account the order
                // number alone cannot say which item holds it. It is also the
                // identifier every recovery step takes, so an operator with
                // only the mail in hand can act from it.
                'order_item_public_id' => (string) $item->public_id,
                'service' => $item->service_type->value,
            ];

            // Only when there is one. A silence with no recorded reason is the
            // case B6 was built for - n8n acknowledged the request and nothing
            // came back - and inventing a reason for it would be a lie in the
            // one place an operator is trusting the row.
            if ($reason !== null) {
                $context['reason'] = $reason;
                $context['blocked'] = $isBlocked;
            }

            $found[$item->id] = [
                'context' => $context,
                'raisable' => $paidAt->greaterThanOrEqualTo($windowOpensAt),
            ];
        }

        return $found;
    }

    /**
     * Placed items whose supplier reads have told us nothing for several
     * sweeps running.
     *
     * The poller counts every fruitless read in `poll_failure_count` - an
     * answer naming none of our challenge ids, an unreachable supplier, a
     * missing key - and resets it the moment one read lands. A count this high
     * means no read has landed for the better part of an hour, which is past
     * the point where backing off again is the only thing to do about it.
     *
     * Deliberately not restricted to jobs carrying a supplier reference. This
     * watches a counter rather than a clock, and the counter only rises when
     * something tried to read the job - so whatever shape a job is in, a high
     * count means reads were attempted and failed, which is worth saying out
     * loud however the row got there.
     *
     * @return array<int, array<string, mixed>>
     */
    private function silent(): array
    {
        $jobs = $this->owed()
            ->where('poll_failure_count', '>=', $this->silentAfterFailures())
            ->get();

        $described = [];

        foreach ($jobs as $job) {
            $item = $job->orderItem;

            if (! $item instanceof OrderItem) {
                continue;
            }

            $described[$item->id] = [
                'order_number' => (string) $item->order->order_number,
                'order_item_public_id' => (string) $item->public_id,
                'service' => $item->service_type->value,
                'supplier' => $job->supplier?->value,
                'supplier_order_id' => $job->supplier_order_id,
                'poll_failures' => (int) $job->poll_failure_count,
            ];
        }

        return $described;
    }

    /**
     * Jobs somebody still owes work on.
     *
     * The job's own status is not enough, and the gap was an alarm that could
     * never close. An admin completing or cancelling an order leaves the job
     * row non-terminal; the poller then stops reading it, because its own
     * selection checks the order too - so `poll_failure_count` freezes at
     * whatever it had reached, the silence condition stays true forever, and
     * `resolve()` has nothing to notice. The row sits open in the panel for an
     * order that was finished weeks ago.
     *
     * The item's own statuses come from `AwaitingPlacement::CLOSED`, the same
     * list the unplaced pass is built on: an item cancelled or refunded on an
     * order that is otherwise still running is owed nothing either, and the two
     * passes must not disagree about what counts as finished.
     *
     * @return Builder<FulfillmentJob>
     */
    private function owed(): Builder
    {
        return FulfillmentJob::query()
            ->whereNotIn('status', [
                FulfillmentStatus::Completed->value,
                FulfillmentStatus::Cancelled->value,
                FulfillmentStatus::Failed->value,
            ])
            ->whereHas('orderItem', static fn (Builder $query) => $query
                ->whereNotIn('status', array_map(
                    static fn (OrderItemStatus $status): string => $status->value,
                    AwaitingPlacement::CLOSED,
                ))
                ->whereHas('order', static fn (Builder $query) => $query
                    ->whereNotIn('status', [
                        OrderStatus::Completed->value,
                        OrderStatus::Cancelled->value,
                        OrderStatus::Refunded->value,
                    ])))
            ->with('orderItem.order')
            ->orderBy('id');
    }

    /**
     * The subset an unraised alarm may be opened for.
     *
     * Everything older is left alone deliberately. An alarm starts watching
     * when it ships; without this a first run on a store that has been
     * fulfilling through n8n's own pipeline would open an alarm for every
     * automated item ever sold and mail the lot of them.
     *
     * @param  array<int, array{context: array<string, mixed>, raisable: bool}>  $found
     * @return array<int, array<string, mixed>>
     */
    private function raisable(array $found): array
    {
        $raisable = [];

        foreach ($found as $orderItemId => $row) {
            if ($row['raisable']) {
                $raisable[$orderItemId] = $row['context'];
            }
        }

        return $raisable;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function raise(FulfillmentAlarmKind $kind, array $items, CarbonImmutable $now): int
    {
        $raised = 0;

        foreach ($items as $orderItemId => $context) {
            $alarm = FulfillmentAlarm::query()->firstOrNew([
                'order_item_id' => $orderItemId,
                'kind' => $kind,
            ]);

            // An open alarm only has its context refreshed: re-stamping
            // raised_at would keep resetting the age an operator reads, and
            // clearing notified_at would mail the same silence every sweep.
            if ($alarm->exists && $alarm->resolved_at === null) {
                $alarm->context = $context;
                $alarm->save();

                continue;
            }

            $alarm->fill([
                'raised_at' => $now,
                'notified_at' => null,
                'resolved_at' => null,
                'context' => $context,
            ])->save();

            $raised++;
        }

        return $raised;
    }

    /**
     * @param  list<int>  $stillSilent
     */
    private function resolve(FulfillmentAlarmKind $kind, array $stillSilent, CarbonImmutable $now): int
    {
        return FulfillmentAlarm::query()
            ->where('kind', $kind->value)
            ->whereNull('resolved_at')
            ->when($stillSilent !== [], static fn ($query) => $query->whereNotIn('order_item_id', $stillSilent))
            ->update(['resolved_at' => $now]);
    }

    private function unplacedAfterMinutes(): int
    {
        return max(1, (int) config('services.suppliers.alarm.unplaced_after_minutes', 15));
    }

    private function blockedAfterMinutes(): int
    {
        return max(1, (int) config('services.suppliers.alarm.blocked_after_minutes', 5));
    }

    private function silentAfterFailures(): int
    {
        return max(1, (int) config('services.suppliers.alarm.silent_after_failures', 6));
    }

    private function raiseWindowHours(): int
    {
        return max(1, (int) config('services.suppliers.alarm.raise_window_hours', 48));
    }
}
