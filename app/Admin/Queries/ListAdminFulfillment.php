<?php

namespace App\Admin\Queries;

use App\Enums\FulfillmentAlarmKind;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PollBand;
use App\Enums\ServiceType;
use App\Enums\SupplierAction;
use App\Support\Orders\AwaitingPlacement;
use App\Support\Orders\PlacementBlockers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The fulfillment queue: every paid item a supplier still owes.
 *
 * This lists **order items**, not `fulfillment_jobs` rows, and the difference
 * is the whole point of the screen. An item that was paid for and never placed
 * has no job row at all - it is the one silence neither n8n nor the poller can
 * report - so a query over jobs would be blind to exactly the failure the
 * operations slice exists to surface. The job is a left join.
 *
 * Three things are read per page rather than per row, the way
 * `ListAdminOrders` reads its users and items: the placements, the open
 * alarms, and the blocker reasons. Correlated subqueries for all of them would
 * be five joins deep for data the page needs in one shape.
 *
 * The cost column is the one permission-dependent projection. It is omitted
 * from the SELECT and absent from the row - not null, absent - when the actor
 * may not see it, so a payload read in a browser's network tab carries no
 * trace of what a shipment cost us.
 *
 * @phpstan-type AdminFulfillmentFilters array{
 *     search?: ?string,
 *     supplier?: ?string,
 *     phase?: ?string,
 *     status?: ?string,
 *     alarm?: ?string,
 *     hold?: ?string,
 *     service?: ?string,
 *     paid_from?: ?string,
 *     paid_to?: ?string,
 *     sort?: string,
 *     direction?: string,
 *     per_page?: int,
 *     page?: int
 * }
 */
final class ListAdminFulfillment
{
    /**
     * The services a supplier bot delivers.
     *
     * The same positive list `RecordSupplierPlacement` uses, and positive for
     * the same reason: the complement of `ServiceType::isManual()` would
     * include Objectives, which no supplier delivers.
     *
     * @var list<ServiceType>
     */
    private const AUTOMATED_SERVICES = [ServiceType::Coins, ServiceType::Sbc];

    /**
     * The sort keys, mapped to the column each one orders by.
     *
     * `actual_cost` is in this list but is refused for an actor without
     * `fulfillment.view_cost` before the request reaches here
     * (`ListAdminFulfillment` the form request). Enforcing it in two places is
     * deliberate: the validator gives a 422 the caller can read, and this
     * fallback means a future caller that skips the validator cannot order by
     * a column it may not read.
     *
     * @var array<string, string>
     */
    private const SORTS = [
        'paid_at' => 'orders.paid_at',
        'placed_at' => 'newest_placed_at',
        'observed_at' => 'fulfillment_jobs.observed_at',
        'poll_failures' => 'fulfillment_jobs.poll_failure_count',
        'actual_cost' => 'fulfillment_jobs.actual_cost_halalah',
    ];

    /**
     * @param  AdminFulfillmentFilters  $filters
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array{currentPage: int, lastPage: int, perPage: int, total: int, from: ?int, to: ?int}
     * }
     */
    public function paginate(array $filters, bool $withCost, bool $canAct): array
    {
        $query = $this->filteredQuery($filters, $withCost);

        $columns = [
            'order_items.id',
            'order_items.public_id',
            'order_items.order_id',
            'order_items.service_type',
            'order_items.platform',
            'order_items.status as item_status',
            'orders.order_number',
            'orders.public_id as order_public_id',
            'orders.paid_at',
            'fulfillment_jobs.id as job_id',
            'fulfillment_jobs.status as job_status',
            'fulfillment_jobs.supplier as job_supplier',
            'fulfillment_jobs.supplier_order_id as job_supplier_order_id',
            'fulfillment_jobs.delivery_phase',
            'fulfillment_jobs.hold_reason',
            'fulfillment_jobs.presentation',
            'fulfillment_jobs.observed_state',
            'fulfillment_jobs.observed_at',
            'fulfillment_jobs.last_viewed_at',
            'fulfillment_jobs.poll_failure_count',
            'fulfillment_jobs.allowed_actions',
            'fulfillment_jobs.coins_delivered',
            'fulfillment_jobs.coins_ordered',
            'fulfillment_jobs.solves_done',
            'fulfillment_jobs.solves_total',
            'fulfillment_jobs.squads_done',
            'fulfillment_jobs.squads_total',
        ];

        // The one permission-dependent column. Absent rather than nulled: see
        // the class docblock.
        if ($withCost) {
            $columns[] = 'fulfillment_jobs.actual_cost_halalah';
        }

        $paginator = $query->select($columns)
            ->selectSub($this->newestPlacedAt(), 'newest_placed_at')
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 15),
                page: (int) ($filters['page'] ?? 1),
            );

        /** @var list<stdClass> $rows */
        $rows = array_values($paginator->items());

        return [
            'items' => $this->project(
                $rows,
                $this->placementsByJobId($rows),
                $this->alarmsByItemId($rows),
                PlacementBlockers::reasons($this->unplacedOrderPublicIds($rows)),
                $withCost,
                $canAct,
            ),
            'pagination' => $this->pagination($paginator),
        ];
    }

    /**
     * Items in fulfillment: paid, automated, still open, on a live order.
     *
     * The item and order status lists are `AwaitingPlacement::CLOSED` and the
     * terminal order statuses, read from the same places the alarm sweep reads
     * them, so the panel and the alarms cannot disagree about what counts as
     * finished.
     *
     * @param  AdminFulfillmentFilters  $filters
     */
    private function filteredQuery(array $filters, bool $withCost): Builder
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('fulfillment_jobs', 'fulfillment_jobs.order_item_id', '=', 'order_items.id')
            ->whereIn('order_items.service_type', array_map(
                static fn (ServiceType $service): string => $service->value,
                self::AUTOMATED_SERVICES,
            ))
            ->whereNotIn('order_items.status', array_map(
                static fn (OrderItemStatus $status): string => $status->value,
                AwaitingPlacement::CLOSED,
            ))
            ->whereNotNull('orders.paid_at')
            ->whereNotIn('orders.status', [
                OrderStatus::Completed->value,
                OrderStatus::Cancelled->value,
                OrderStatus::Refunded->value,
            ]);

        $this->applySearch($query, $filters['search'] ?? null);
        $this->applyPlacementFilters($query, $filters['supplier'] ?? null, $filters['phase'] ?? null);
        $this->applyAlarmFilter($query, $filters['alarm'] ?? null);
        $this->applyHoldFilter($query, $filters['hold'] ?? null);

        if (! empty($filters['status'])) {
            $query->where('fulfillment_jobs.status', $filters['status']);
        }

        if (! empty($filters['service'])) {
            $query->where('order_items.service_type', $filters['service']);
        }

        $this->applyDateFilters($query, $filters['paid_from'] ?? null, $filters['paid_to'] ?? null);

        $sortKey = (string) ($filters['sort'] ?? 'paid_at');

        if (! array_key_exists($sortKey, self::SORTS) || ($sortKey === 'actual_cost' && ! $withCost)) {
            $sortKey = 'paid_at';
        }

        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        // `newest_placed_at` is the alias the SELECT already carries, so this
        // orders by a value the query computes once rather than evaluating the
        // same correlated subquery a second time. The count query drops both
        // the columns and the orders, so the alias going with them is fine.
        $query->orderBy(self::SORTS[$sortKey], $direction);

        // A stable tiebreak, so page two never repeats a row from page one.
        return $query->orderBy('order_items.id', $direction);
    }

    /**
     * Exact matches only, the way `ListAdminOrders::applySearch` does it.
     *
     * A wildcard over a supplier reference has no index to stand on, and an
     * operator arriving here has an order number or a reference in hand from
     * the alarm mail, not a fragment of one.
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        $query->where(function (Builder $outer) use ($search): void {
            $outer->where('orders.order_number', $search)
                ->orWhere('orders.order_number', 'AUT-'.$search)
                ->orWhere('order_items.public_id', $search)
                ->orWhereExists(function (Builder $placements) use ($search): void {
                    $placements->select(DB::raw(1))
                        ->from('fulfillment_placements')
                        ->whereColumn('fulfillment_placements.fulfillment_job_id', 'fulfillment_jobs.id')
                        ->where('fulfillment_placements.supplier_order_id', $search);
                });
        });
    }

    /**
     * Supplier and phase are read from the placements, never from the job.
     *
     * The job's supplier column mirrors the FIRST placement only - the unique
     * index was dropped from it precisely so a challenge item's second phase
     * could exist - so filtering on the job would silently miss a challenge
     * placed at a different supplier than its coins.
     */
    private function applyPlacementFilters(Builder $query, ?string $supplier, ?string $phase): void
    {
        if (empty($supplier) && empty($phase)) {
            return;
        }

        $query->whereExists(function (Builder $placements) use ($supplier, $phase): void {
            $placements->select(DB::raw(1))
                ->from('fulfillment_placements')
                ->whereColumn('fulfillment_placements.fulfillment_job_id', 'fulfillment_jobs.id');

            if (! empty($supplier)) {
                $placements->where('fulfillment_placements.supplier', $supplier);
            }

            if (! empty($phase)) {
                $placements->where('fulfillment_placements.delivery_phase', $phase);
            }
        });
    }

    /**
     * `unplaced`, `silent`, `stalled`, `any` or `none`.
     *
     * Only open alarms count. A resolved row is history - the sweep keeps it
     * so a returning silence reuses it - and an operator filtering for "what
     * is wrong now" means now.
     */
    private function applyAlarmFilter(Builder $query, ?string $alarm): void
    {
        if (empty($alarm)) {
            return;
        }

        $exists = static function (Builder $alarms) use ($alarm): void {
            $alarms->select(DB::raw(1))
                ->from('fulfillment_alarms')
                ->whereColumn('fulfillment_alarms.order_item_id', 'order_items.id')
                ->whereNull('fulfillment_alarms.resolved_at');

            if (! in_array($alarm, ['any', 'none'], true)) {
                $alarms->where('fulfillment_alarms.kind', $alarm);
            }
        };

        if ($alarm === 'none') {
            $query->whereNotExists($exists);

            return;
        }

        $query->whereExists($exists);
    }

    /** `held`, `clear`, or one of the 21 `OrderHoldReason` values. */
    private function applyHoldFilter(Builder $query, ?string $hold): void
    {
        if (empty($hold)) {
            return;
        }

        if ($hold === 'held') {
            $query->whereNotNull('fulfillment_jobs.hold_reason');

            return;
        }

        if ($hold === 'clear') {
            $query->whereNull('fulfillment_jobs.hold_reason');

            return;
        }

        $query->where('fulfillment_jobs.hold_reason', $hold);
    }

    /**
     * Parsed exactly as `ListAdminOrders::applyDateFilters` parses its own, so
     * a date means the same day on both screens.
     */
    private function applyDateFilters(Builder $query, ?string $from, ?string $to): void
    {
        if (! empty($from)) {
            $query->where('orders.paid_at', '>=', Carbon::createFromFormat('Y-m-d', (string) $from, 'UTC')->startOfDay());
        }

        if (! empty($to)) {
            $query->where('orders.paid_at', '<', Carbon::createFromFormat('Y-m-d', (string) $to, 'UTC')->addDay()->startOfDay());
        }
    }

    /**
     * When the supplier most recently took work on this item.
     *
     * A correlated subquery rather than a join, because a job can carry two
     * placements and a join would double the row.
     */
    private function newestPlacedAt(): Builder
    {
        return DB::table('fulfillment_placements')
            ->selectRaw('max(placed_at)')
            ->whereColumn('fulfillment_placements.fulfillment_job_id', 'fulfillment_jobs.id');
    }

    /**
     * Every placement on this page's jobs, newest last.
     *
     * @param  list<stdClass>  $rows
     * @return array<int, list<stdClass>>
     */
    private function placementsByJobId(array $rows): array
    {
        $jobIds = $this->jobIds($rows);

        if ($jobIds === []) {
            return [];
        }

        $byJob = [];

        foreach (DB::table('fulfillment_placements')
            ->whereIn('fulfillment_job_id', $jobIds)
            ->select([
                'fulfillment_job_id',
                'delivery_phase',
                'supplier',
                'supplier_order_id',
                'supplier_challenge_ids',
                'placed_at',
            ])
            ->orderBy('id')
            ->get() as $placement) {
            $byJob[(int) $placement->fulfillment_job_id][] = $placement;
        }

        return $byJob;
    }

    /**
     * The open alarms on this page's items.
     *
     * At most one row per item per kind (the table's unique index), and an
     * item can legitimately carry two kinds at once - `unplaced` while nothing
     * has been placed is not exclusive with anything - so this is a list.
     *
     * @param  list<stdClass>  $rows
     * @return array<int, list<stdClass>>
     */
    private function alarmsByItemId(array $rows): array
    {
        $itemIds = array_map(static fn (stdClass $row): int => (int) $row->id, $rows);

        if ($itemIds === []) {
            return [];
        }

        $byItem = [];

        foreach (DB::table('fulfillment_alarms')
            ->whereIn('order_item_id', $itemIds)
            ->whereNull('resolved_at')
            ->select(['order_item_id', 'kind', 'raised_at', 'notified_at', 'context'])
            ->orderBy('id')
            ->get() as $alarm) {
            $byItem[(int) $alarm->order_item_id][] = $alarm;
        }

        return $byItem;
    }

    /**
     * The orders whose rows have no job, which are the only ones a blocker
     * reason can apply to.
     *
     * `PlacementBlockers` is keyed on the order because composition fails the
     * whole request rather than one item of it, and it reads the reason the
     * publisher already stored. Asking it only about unplaced rows keeps the
     * `whereIn` small on a page of healthy items.
     *
     * @param  list<stdClass>  $rows
     * @return list<string>
     */
    private function unplacedOrderPublicIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ($row->job_id === null) {
                $ids[] = (string) $row->order_public_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<int>
     */
    private function jobIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ($row->job_id !== null) {
                $ids[] = (int) $row->job_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<stdClass>  $rows
     * @param  array<int, list<stdClass>>  $placementsByJob
     * @param  array<int, list<stdClass>>  $alarmsByItem
     * @param  array<string, string>  $reasons
     * @return list<array<string, mixed>>
     */
    private function project(
        array $rows,
        array $placementsByJob,
        array $alarmsByItem,
        array $reasons,
        bool $withCost,
        bool $canAct,
    ): array {
        return array_map(function (stdClass $row) use ($placementsByJob, $alarmsByItem, $reasons, $withCost, $canAct): array {
            $jobId = $row->job_id === null ? null : (int) $row->job_id;
            $placements = $jobId === null ? [] : ($placementsByJob[$jobId] ?? []);
            $alarms = $alarmsByItem[(int) $row->id] ?? [];
            $current = $this->currentPlacement($placements, $row->delivery_phase);

            $projected = [
                'id' => (string) $row->public_id,
                'orderNumber' => (string) $row->order_number,
                'service' => (string) $row->service_type,
                'platform' => (string) $row->platform,
                'itemStatus' => (string) $row->item_status,
                'paidAt' => $this->iso($row->paid_at),
                'progress' => $this->progress($row),
                'job' => $jobId === null ? null : [
                    'status' => (string) $row->job_status,
                    'phase' => $row->delivery_phase === null ? null : (string) $row->delivery_phase,
                    'holdReason' => $row->hold_reason === null ? null : (string) $row->hold_reason,
                    'presentation' => $row->presentation === null ? null : (string) $row->presentation,
                    'observedState' => $row->observed_state === null ? null : (string) $row->observed_state,
                    'observedAt' => $this->iso($row->observed_at),
                    'pollFailures' => (int) $row->poll_failure_count,
                    'band' => PollBand::for($this->immutable($row->last_viewed_at))->value,
                ],
                'placement' => $current === null ? null : [
                    'supplier' => (string) $current->supplier,
                    'reference' => (string) $current->supplier_order_id,
                    'phase' => (string) $current->delivery_phase,
                    'placedAt' => $this->iso($current->placed_at),
                    'challengeCount' => $this->challengeCount($current),
                ],
                'alarms' => array_map(fn (stdClass $alarm): array => [
                    'kind' => (string) $alarm->kind,
                    'raisedAt' => $this->iso($alarm->raised_at),
                    'notifiedAt' => $this->iso($alarm->notified_at),
                    ...$this->alarmDetail($alarm),
                ], $alarms),
                // The publisher's own word for what stopped it, passed through
                // rather than translated, because it is the spelling in the
                // log, in `integration_events.last_error` and in the alarm
                // mail. Only present on a row with no job: a placed item's
                // trouble is at the supplier, not in the store.
                'blocker' => $jobId !== null ? null : $this->blocker($reasons[(string) $row->order_public_id] ?? null),
                'actions' => $canAct ? $this->actions($row, $jobId !== null) : [],
            ];

            if ($withCost) {
                $projected['cost'] = $row->actual_cost_halalah === null ? null : [
                    'amountMinor' => (string) $row->actual_cost_halalah,
                    'currency' => 'SAR',
                ];
            }

            return $projected;
        }, $rows);
    }

    /**
     * The placement the row is about.
     *
     * The one matching the job's current phase when there is one - a challenge
     * item that has moved on to solving is at its challenge placement, not its
     * coins one - and otherwise the newest. Both beat the job's own mirror
     * columns, which only ever advertise the first placement.
     *
     * @param  list<stdClass>  $placements
     */
    private function currentPlacement(array $placements, mixed $jobPhase): ?stdClass
    {
        if ($placements === []) {
            return null;
        }

        if (is_string($jobPhase) && $jobPhase !== '') {
            foreach (array_reverse($placements) as $placement) {
                if ((string) $placement->delivery_phase === $jobPhase) {
                    return $placement;
                }
            }
        }

        return $placements[count($placements) - 1];
    }

    /** @return array{done: int, total: int, unit: string}|null */
    private function progress(stdClass $row): ?array
    {
        if ($row->job_id === null) {
            return null;
        }

        // A challenge item's meaningful count is its solves; a coins item's is
        // its coins. Squads are the inside of one solve and change too fast to
        // read in a list.
        if ($row->solves_total !== null) {
            return ['done' => (int) $row->solves_done, 'total' => (int) $row->solves_total, 'unit' => 'solves'];
        }

        if ($row->coins_ordered !== null) {
            return ['done' => (int) $row->coins_delivered, 'total' => (int) $row->coins_ordered, 'unit' => 'coins'];
        }

        return null;
    }

    /**
     * The three facts that make a stall triageable, read the way
     * `AlertOwnerOfFulfillmentSilence::stalledDetail()` reads them.
     *
     * `circuit_open` is the one that changes what an operator should do: a
     * supplier inside its cooldown is one we are choosing not to ask, not one
     * that has gone quiet, and sending somebody after a supplier that is
     * answering fine is sending them to the wrong place. It reaches the row as
     * its own flag so the screen can say which of the two sentences is true,
     * exactly as the mail does.
     *
     * @return array{pollFailures: int|null, quietMinutes: int|null, circuitOpen: bool, phase: string|null, blocked: bool, reason: string|null}
     */
    private function alarmDetail(stdClass $alarm): array
    {
        $context = json_decode((string) ($alarm->context ?? '{}'), true);
        $context = is_array($context) ? $context : [];

        $failures = $context['poll_failures'] ?? null;
        $quiet = $context['quiet_minutes'] ?? null;
        $phase = $context['phase'] ?? null;
        $reason = $context['reason'] ?? null;

        return [
            'pollFailures' => is_numeric($failures) ? (int) $failures : null,
            'quietMinutes' => is_numeric($quiet) ? (int) $quiet : null,
            'circuitOpen' => ($context['circuit_open'] ?? false) === true,
            'phase' => is_string($phase) && $phase !== '' ? $phase : null,
            'blocked' => ($context['blocked'] ?? false) === true,
            'reason' => is_string($reason) && $reason !== '' ? $reason : null,
        ];
    }

    /** @return array{reason: string, blocks: bool}|null */
    private function blocker(?string $reason): ?array
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        return ['reason' => $reason, 'blocks' => PlacementBlockers::blocks($reason)];
    }

    /**
     * What this row's action control may offer.
     *
     * `send` exists only where there is no job. That is not a convenience: an
     * item that already has a placement must never be offered a second one,
     * and the control not existing is the defence rather than the 409
     * `RecordSupplierPlacement` would eventually answer with.
     *
     * `resume` and `retry_challenge` come from the job's own
     * `allowed_actions`, the same column `ResolveActionableItem` re-checks
     * before anything is sent, so the button set and the gate behind it read
     * one fact.
     *
     * @return list<string>
     */
    private function actions(stdClass $row, bool $hasJob): array
    {
        if (! $hasJob) {
            return ['send'];
        }

        $stored = json_decode((string) ($row->allowed_actions ?? '[]'), true);

        if (! is_array($stored)) {
            return [];
        }

        $actions = [];

        foreach ($stored as $value) {
            if (! is_string($value)) {
                continue;
            }

            $action = SupplierAction::tryFrom($value);

            // EditCredentials is the customer's own action on their order
            // page, never ours: an operator cannot retype somebody's EA
            // password for them, and offering it here would suggest they can.
            if ($action === SupplierAction::Resume || $action === SupplierAction::RetryChallenge) {
                $actions[] = $action->value;
            }
        }

        return array_values(array_unique($actions));
    }

    private function challengeCount(stdClass $placement): int
    {
        $ids = json_decode((string) ($placement->supplier_challenge_ids ?? '[]'), true);

        return is_array($ids) ? count($ids) : 0;
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, 'UTC')->utc()->toIso8601String();
    }

    private function immutable(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC');
    }

    /** @return list<string> */
    public static function alarmKinds(): array
    {
        return array_map(
            static fn (FulfillmentAlarmKind $kind): string => $kind->value,
            FulfillmentAlarmKind::cases(),
        );
    }

    /** @return list<string> */
    public static function sortKeys(): array
    {
        return array_keys(self::SORTS);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{currentPage: int, lastPage: int, perPage: int, total: int, from: ?int, to: ?int}
     */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
