<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentAlarmKind;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\FulfillmentAlarm;
use App\Models\FulfillmentJob;
use App\Models\OrderItem;
use App\Support\Orders\AwaitingPlacement;
use App\Support\Orders\PlacementBlockers;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Brings the alarm table level with what is actually silent right now.
 *
 * Three silences, none of which anything else can report. An automated item
 * that was paid for and never placed is invisible to n8n - from its side the
 * placement succeeded and the callback was lost - and invisible to the poller,
 * which only reads jobs that already carry a supplier reference. A placed item
 * whose reads keep coming back empty is visible to the poller, which dutifully
 * backs off and retries it forever without ever saying so out loud. And a
 * placed item nothing is reading at all is visible to nobody: the failure
 * counter the second alarm watches only moves when a read is attempted, so a
 * dead scheduler leaves every job looking exactly as healthy as it did the
 * minute before the cron stopped.
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
        $unread = $this->unread($now);

        $raised = $this->raise(FulfillmentAlarmKind::Unplaced, $this->raisable($unplaced), $now)
            + $this->raise(FulfillmentAlarmKind::Silent, $silent, $now)
            + $this->raise(FulfillmentAlarmKind::Stale, $this->raisable($unread), $now);

        // Resolution reads the unwindowed sets on purpose. An alarm is closed
        // when its condition is gone, never because the item aged out of the
        // window that was allowed to raise it - an order that stayed lost for
        // three days is still lost.
        $resolved = $this->resolve(FulfillmentAlarmKind::Unplaced, array_keys($unplaced), $now)
            + $this->resolve(FulfillmentAlarmKind::Silent, array_keys($silent), $now)
            + $this->resolve(FulfillmentAlarmKind::Stale, array_keys($unread), $now);

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
     * @return array<int, array<string, mixed>>
     */
    private function silent(): array
    {
        $jobs = FulfillmentJob::query()
            ->whereNotIn('status', [
                FulfillmentStatus::Completed->value,
                FulfillmentStatus::Cancelled->value,
                FulfillmentStatus::Failed->value,
            ])
            ->where('poll_failure_count', '>=', $this->silentAfterFailures())
            ->with('orderItem.order')
            ->orderBy('id')
            ->get();

        $described = [];

        foreach ($jobs as $job) {
            $item = $job->orderItem;

            if (! $item instanceof OrderItem) {
                continue;
            }

            $described[$item->id] = [
                'order_number' => (string) $item->order->order_number,
                'service' => $item->service_type->value,
                'supplier' => $job->supplier?->value,
                'supplier_order_id' => $job->supplier_order_id,
                'poll_failures' => (int) $job->poll_failure_count,
            ];
        }

        return $described;
    }

    /**
     * Placed items whose newest observation is older than any cadence explains.
     *
     * The poller's slowest healthy gap is its background cadence doubling to a
     * ten-minute ceiling, and a supplier whose circuit opens is left alone for
     * a minute - so an hour without a reading is not a slow band, it is a band
     * nobody is walking. The jobs this catches are exactly the ones the failure
     * counter cannot: a job is only counted as failing when something tried to
     * read it, so a tick that never ran, never reached this row, or died on its
     * deadline leaves the counter at whatever it was and the row looking calm.
     *
     * Jobs already failing their reads are left to the Silent alarm rather than
     * carrying both. When a stale job starts failing, one sweep resolves this
     * alarm and opens that one, which is the honest reading: the fault changed
     * from nobody asking to the supplier not answering.
     *
     * @return array<int, array{context: array<string, mixed>, raisable: bool}>
     */
    private function unread(CarbonImmutable $now): array
    {
        $cutoff = $now->subMinutes($this->staleAfterMinutes());
        $windowOpensAt = $now->subHours($this->raiseWindowHours());

        $jobs = FulfillmentJob::query()
            ->whereNotIn('status', [
                FulfillmentStatus::Completed->value,
                FulfillmentStatus::Cancelled->value,
                FulfillmentStatus::Failed->value,
            ])
            // The poller's own selection: a job with no reference is not one it
            // reads, so its observation cannot age. Those belong to Unplaced.
            ->whereNotNull('supplier')
            ->whereNotNull('supplier_order_id')
            ->where('supplier_order_id', '!=', '')
            ->where('poll_failure_count', '<', $this->silentAfterFailures())
            ->where(static fn (Builder $query) => $query
                ->where('observed_at', '<=', $cutoff)
                // Never read at all: age from when the placement was recorded,
                // so a job created seconds ago is not instantly overdue.
                ->orWhere(static fn (Builder $query) => $query
                    ->whereNull('observed_at')
                    ->where('created_at', '<=', $cutoff)))
            // An admin can close an order without touching the job row, and the
            // reconciler stops polling a job whose order went terminal. Neither
            // is owed a reading, so neither is silence.
            ->whereHas('orderItem.order', static fn (Builder $query) => $query
                ->whereNotIn('status', [
                    OrderStatus::Completed->value,
                    OrderStatus::Cancelled->value,
                    OrderStatus::Refunded->value,
                ]))
            ->with('orderItem.order')
            ->orderBy('id')
            ->get();

        return $this->describeUnread($jobs, $windowOpensAt);
    }

    /**
     * @param  Collection<int, FulfillmentJob>  $jobs
     * @return array<int, array{context: array<string, mixed>, raisable: bool}>
     */
    private function describeUnread(Collection $jobs, CarbonImmutable $windowOpensAt): array
    {
        $found = [];

        foreach ($jobs as $job) {
            $item = $job->orderItem;

            if (! $item instanceof OrderItem) {
                continue;
            }

            $since = $job->observed_at ?? $job->created_at;

            $found[$item->id] = [
                'context' => [
                    'order_number' => (string) $item->order->order_number,
                    'service' => $item->service_type->value,
                    'supplier' => $job->supplier?->value,
                    'supplier_order_id' => $job->supplier_order_id,
                    // Null means no supplier reading has ever landed on this
                    // job, which reads differently from one that went quiet.
                    'observed_at' => $job->observed_at?->toIso8601String(),
                ],
                // The window is measured on the reading rather than on the
                // order: a job paid for last week and polled until an hour ago
                // is today's outage, and an order that was already silent when
                // this shipped is history the first sweep must not mail.
                'raisable' => $since instanceof CarbonInterface
                    && $since->greaterThanOrEqualTo($windowOpensAt),
            ];
        }

        return $found;
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

    private function staleAfterMinutes(): int
    {
        return max(1, (int) config('services.suppliers.alarm.stale_after_minutes', 60));
    }

    private function raiseWindowHours(): int
    {
        return max(1, (int) config('services.suppliers.alarm.raise_window_hours', 48));
    }
}
