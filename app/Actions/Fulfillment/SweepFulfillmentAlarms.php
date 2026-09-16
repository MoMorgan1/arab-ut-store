<?php

namespace App\Actions\Fulfillment;

use App\Enums\FulfillmentAlarmKind;
use App\Enums\FulfillmentStatus;
use App\Models\FulfillmentAlarm;
use App\Models\FulfillmentJob;
use App\Models\OrderItem;
use App\Support\Orders\AwaitingPlacement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

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

        $raised = $this->raise(FulfillmentAlarmKind::Unplaced, $this->raisable($now), $now)
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
     * Every paid automated item no supplier was ever asked to deliver.
     *
     * @return array<int, array<string, mixed>>
     */
    private function unplaced(CarbonImmutable $now): array
    {
        return $this->describeItems(
            AwaitingPlacement::stale($now->subMinutes($this->unplacedAfterMinutes())),
        );
    }

    /**
     * The subset an unraised alarm may be opened for.
     *
     * Everything older is left alone deliberately. The alarm starts watching
     * when it ships; without this a first run on a store that has been
     * fulfilling through n8n's own pipeline would open an alarm for every
     * automated item ever sold and mail the lot of them. It is a second query
     * rather than a filter over the set above because the bound belongs in the
     * same place the rest of the predicate lives.
     *
     * @return array<int, array<string, mixed>>
     */
    private function raisable(CarbonImmutable $now): array
    {
        return $this->describeItems(AwaitingPlacement::stale(
            $now->subMinutes($this->unplacedAfterMinutes()),
            $now->subHours($this->raiseWindowHours()),
        ));
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function describeItems($items): array
    {
        $described = [];

        foreach ($items as $item) {
            $described[$item->id] = [
                'order_number' => (string) $item->order->order_number,
                'service' => $item->service_type->value,
            ];
        }

        return $described;
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

    private function silentAfterFailures(): int
    {
        return max(1, (int) config('services.suppliers.alarm.silent_after_failures', 6));
    }

    private function raiseWindowHours(): int
    {
        return max(1, (int) config('services.suppliers.alarm.raise_window_hours', 48));
    }
}
