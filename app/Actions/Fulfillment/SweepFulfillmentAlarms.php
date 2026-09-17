<?php

namespace App\Actions\Fulfillment;

use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentAlarmKind;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PollBand;
use App\Models\FulfillmentAlarm;
use App\Models\FulfillmentJob;
use App\Models\OrderItem;
use App\Suppliers\SupplierGuard;
use App\Support\Orders\AwaitingPlacement;
use App\Support\Orders\PlacementBlockers;
use Carbon\CarbonImmutable;
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
 * backs off and retries it forever without ever saying so out loud. And a job
 * whose readings simply stopped arriving is invisible to both, because a read
 * that is never attempted never fails.
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
     * The cadence-table key for a job carrying no delivery phase.
     *
     * A plain coins job can have a null phase, and null is not an array key,
     * so the table names that case rather than leaving it to a cast.
     */
    private const PHASELESS = 'none';

    /**
     * Whether each supplier's circuit is open, asked once per sweep.
     *
     * @var array<string, bool>
     */
    private array $circuitOpen = [];

    public function __construct(private readonly SupplierGuard $guard) {}

    /**
     * @return array{raised: int, resolved: int, open: int}
     */
    public function execute(): array
    {
        $now = CarbonImmutable::now();
        $this->circuitOpen = [];

        $unplaced = $this->unplaced($now);
        $silent = $this->silent();
        $stalled = $this->stalled($now, null);

        $raised = $this->raise(FulfillmentAlarmKind::Unplaced, $this->raisable($unplaced), $now)
            + $this->raise(FulfillmentAlarmKind::Silent, $silent, $now)
            // Windowed for the same reason Unplaced is: this alarm starts
            // watching when it ships. Without the bound, the first sweep after
            // a deploy opens one for every job that has been sitting since
            // before anybody was measuring, and mails the lot in one go.
            + $this->raise(FulfillmentAlarmKind::Stalled, $this->stalled($now, $now->subHours($this->raiseWindowHours())), $now);

        // Resolution reads the unwindowed sets on purpose. An alarm is closed
        // when its condition is gone, never because the item aged out of the
        // window that was allowed to raise it - an order that stayed lost for
        // three days is still lost.
        $resolved = $this->resolve(FulfillmentAlarmKind::Unplaced, array_keys($unplaced), $now)
            + $this->resolve(FulfillmentAlarmKind::Silent, array_keys($silent), $now)
            + $this->resolve(FulfillmentAlarmKind::Stalled, array_keys($stalled), $now);

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
     * Placed jobs whose newest reading is older than their phase and band allow.
     *
     * One query per phase and band rather than one query with every threshold
     * OR'd together: there are at most six, they run every five minutes over
     * the handful of jobs a supplier is working on, and one threshold's
     * predicate is legible in a way the union of six is not.
     *
     * A phase switched off is skipped entirely and selects nothing at all -
     * see {@see self::stallCadence()}.
     *
     * `$noOlderThan` bounds which silences may open a NEW alarm. Passing null
     * asks for the whole set, which is what resolution needs: an alarm closes
     * when its condition ends, never because the job aged out of the window
     * that was allowed to raise it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stalled(CarbonImmutable $now, ?CarbonImmutable $noOlderThan): array
    {
        $described = [];

        foreach ($this->stallCadence() as $phase => $bands) {
            foreach ($bands as $bandValue => $minutes) {
                $band = PollBand::from($bandValue);
                $cutoff = $now->subMinutes($minutes);

                foreach ($this->stalledInPhase($phase, $band, $now, $cutoff, $noOlderThan) as $job) {
                    $item = $job->orderItem;

                    if (! $item instanceof OrderItem) {
                        continue;
                    }

                    $described[$item->id] = [
                        'order_number' => (string) $item->order->order_number,
                        // The item as well as its order, for the reason the
                        // other two passes carry it: one order can hold
                        // several items at different suppliers, and every
                        // recovery step takes the item id.
                        'order_item_public_id' => (string) $item->public_id,
                        'service' => $item->service_type->value,
                        'supplier' => $job->supplier?->value,
                        'supplier_order_id' => $job->supplier_order_id,
                        'phase' => $phase,
                        'band' => $band->value,
                        'observed_state' => $job->observed_state,
                        // Which of two true sentences this is. A supplier in
                        // its cooldown is one we are choosing not to ask, and
                        // an operator sent to investigate a supplier that is
                        // answering fine has been sent to the wrong place.
                        'circuit_open' => $this->circuitIsOpen($job),
                        // Absolute, because Carbon signs a difference by the order
                        // the two instants were given and a negative age in an
                        // operator's mail is a number nobody trusts again.
                        'quiet_minutes' => $job->observed_at === null
                            ? null
                            : (int) $job->observed_at->diffInMinutes($now, true),
                    ];
                }
            }
        }

        return $described;
    }

    /**
     * Whether this job's supplier is inside a circuit cooldown right now.
     *
     * Asked once per supplier per sweep: the answer comes from the cache and
     * cannot change usefully between two jobs of the same supplier in one pass.
     */
    private function circuitIsOpen(FulfillmentJob $job): bool
    {
        $supplier = $job->supplier;

        if ($supplier === null) {
            return false;
        }

        return $this->circuitOpen[$supplier->value] ??= $this->guard->availableAt($supplier) !== null;
    }

    /**
     * The stalled jobs of one phase, as of one cutoff.
     *
     * The first four conditions are the poller's own selection
     * (`PollFulfillmentJobs::selectDueJobs`), and they are here for a reason
     * rather than for symmetry: this alarm means "the poller should be reading
     * this and no reading is arriving", so anything the poller is deliberately
     * not reading cannot be stalled. A null `next_poll_at` is the reconciler's
     * "stop polling this" marker and a terminal order is finished with
     * whatever its job row still says, and without both checks an order closed
     * by an admin would raise an alarm that no observation could ever resolve.
     *
     * The fifth hands the job over to {@see self::silent()} at the failure
     * count that alarm opens on. The two conditions genuinely overlap - reads
     * that keep failing also stop advancing `observed_at` - and an item that
     * appeared under both would be counted twice on the panel and described
     * two ways in one mail. So the boundary is exclusive: below the count this
     * alarm owns it, at or above it the other one does, and crossing the
     * boundary resolves this one as the other opens. An operator reads a
     * failure count either way, and "reads are failing" is the more specific
     * of the two things to be told.
     *
     * The sixth and seventh are the difference between "no reading is
     * arriving" and "we are deliberately not asking". A job whose
     * `next_poll_at` is in the future is waiting its turn - `deferForCircuit`
     * pushes it there when a supplier is rate-limited, and the ordinary
     * backoff does the same - and calling that a stall would send an operator
     * after a supplier that is behaving. A leased job is being read right now.
     * Both mirror the poller's selection exactly.
     *
     * @return Collection<int, FulfillmentJob>
     */
    private function stalledInPhase(
        string $phase,
        PollBand $band,
        CarbonImmutable $now,
        CarbonImmutable $cutoff,
        ?CarbonImmutable $noOlderThan,
    ): Collection {
        $attentionSince = $now->subSeconds(PollBand::windowSeconds());

        return FulfillmentJob::query()
            ->whereNotIn('status', [
                FulfillmentStatus::Completed->value,
                FulfillmentStatus::Cancelled->value,
                FulfillmentStatus::Failed->value,
            ])
            ->whereNotNull('supplier')
            ->whereNotNull('supplier_order_id')
            ->where('supplier_order_id', '!=', '')
            ->whereNotNull('next_poll_at')
            ->where('next_poll_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('leased_until')->orWhere('leased_until', '<', $now);
            })
            ->where('poll_failure_count', '<', $this->silentAfterFailures())
            ->when(
                $band === PollBand::Attention,
                fn ($query) => $query->where('last_viewed_at', '>=', $attentionSince),
                fn ($query) => $query->where(function ($query) use ($attentionSince): void {
                    $query->whereNull('last_viewed_at')->orWhere('last_viewed_at', '<', $attentionSince);
                }),
            )
            ->whereHas('orderItem.order', function ($query): void {
                $query->whereNotIn('status', [
                    OrderStatus::Completed->value,
                    OrderStatus::Cancelled->value,
                    OrderStatus::Refunded->value,
                ]);
            })
            ->when(
                $phase === self::PHASELESS,
                fn ($query) => $query->whereNull('delivery_phase'),
                fn ($query) => $query->where('delivery_phase', $phase),
            )
            ->where(function ($query) use ($cutoff, $noOlderThan): void {
                $query->where(function ($query) use ($cutoff, $noOlderThan): void {
                    $query->where('observed_at', '<', $cutoff)
                        ->when($noOlderThan !== null, fn ($query) => $query->where('observed_at', '>=', $noOlderThan));
                })
                    // Never read at all, and old enough that it should have
                    // been. Measured from the newest placement rather than
                    // from the job row, which can predate any supplier being
                    // handed the work (`RecordSupplierPlacement` adopts an
                    // existing row). A job with no placement row is left alone:
                    // there is no honest instant to measure it from, and
                    // guessing one is how an alarm starts crying wolf.
                    ->orWhere(function ($query) use ($cutoff, $noOlderThan): void {
                        $query->whereNull('observed_at')
                            ->has('placements')
                            ->whereDoesntHave('placements', function ($placements) use ($cutoff): void {
                                $placements->where('placed_at', '>=', $cutoff);
                            })
                            ->when($noOlderThan !== null, fn ($query) => $query->whereHas(
                                'placements',
                                fn ($placements) => $placements->where('placed_at', '>=', $noOlderThan),
                            ));
                    });
            })
            ->with('orderItem.order')
            ->orderBy('id')
            ->get();
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

    /**
     * How long each watched phase may go without a reading landing, per band.
     *
     * Two settings, and the difference between them is the whole design. The
     * fallback is what a phase nobody has measured uses, so detection works
     * from the day it ships instead of waiting on numbers that do not exist
     * yet. The per-phase table is how a measured number replaces it - and how
     * a phase is switched off, because a phase named in the table with a null
     * is an answer ("do not watch this") while a phase missing from it is the
     * absence of one.
     *
     * So the lookup is `array_key_exists`, not `??`. With `??` a null entry
     * and a missing key would be the same thing, the fallback would answer for
     * both, and the off switch would quietly stop existing.
     *
     * The fallback is per band because one number cannot serve both: the bands
     * differ by more than seven times, so an hour is twenty missed reads in one
     * and a hundred and forty in the other. A measured per-phase number
     * overrides both bands of its phase for now - if the measurements show the
     * bands need their own, this is where that grows.
     *
     * A key the enum does not name is ignored, so a typo in the table leaves
     * its phase on the fallback rather than turning it into a threshold.
     *
     * @return array<string, array<string, int>> phase => band value => minutes
     */
    private function stallCadence(): array
    {
        $configured = config('services.suppliers.alarm.stalled_after_minutes');
        $configured = is_array($configured) ? $configured : [];

        $cadence = [];

        foreach ($this->watchablePhases() as $phase) {
            $override = array_key_exists($phase, $configured)
                ? $this->positiveMinutes($configured[$phase])
                : null;

            foreach (PollBand::cases() as $band) {
                $minutes = array_key_exists($phase, $configured)
                    ? $override
                    : $this->fallbackMinutes($band);

                if ($minutes !== null) {
                    $cadence[$phase][$band->value] = $minutes;
                }
            }
        }

        return $cadence;
    }

    private function fallbackMinutes(PollBand $band): ?int
    {
        $fallback = config('services.suppliers.alarm.stalled_fallback_minutes');

        return is_array($fallback)
            ? $this->positiveMinutes($fallback[$band->value] ?? null)
            : null;
    }

    /**
     * Every phase the cadence table may name.
     *
     * @return list<string>
     */
    private function watchablePhases(): array
    {
        $phases = array_map(
            static fn (DeliveryPhase $phase): string => $phase->value,
            DeliveryPhase::cases(),
        );

        $phases[] = self::PHASELESS;

        return $phases;
    }

    /**
     * A threshold, or null for "there isn't one".
     *
     * Null, a word, an empty string, zero and a negative are all the same
     * answer: not a duration. Letting any of them fall through to zero the way
     * `(int) null` would calls every open job stalled the moment it ships.
     */
    private function positiveMinutes(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }
}
