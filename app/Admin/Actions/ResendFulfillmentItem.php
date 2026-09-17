<?php

namespace App\Admin\Actions;

use App\Actions\Fulfillment\EnqueueOrderPlacement;
use App\Actions\Fulfillment\ResumeItemDelivery;
use App\Actions\Fulfillment\RetryItemChallenge;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\FulfillmentResendAction;
use App\Enums\FulfillmentResendOutcome;
use App\Enums\OrderStatus;
use App\Enums\ServiceType;
use App\Enums\SupplierAction;
use App\Models\FulfillmentJob;
use App\Models\IntegrationEvent;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Orders\AwaitingPlacement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Puts one item back in front of a supplier, at an operator's request.
 *
 * "Re-send" means two unrelated things and conflating them is how an order
 * gets paid for twice, so this decides which from the row rather than from the
 * caller:
 *
 * - **An item with no fulfillment job** was never placed. Its order's one
 *   `order.paid` outbox row is re-opened so the publisher delivers it again.
 *   A second event cannot be created - the idempotency key is
 *   `order-paid:<id>` and the column is unique - and re-delivery is safe
 *   because `ComposePlacementRequest` re-reads `AwaitingPlacement` at send
 *   time and drops every item that has gained a job since. The request that
 *   goes out can only contain work nobody is doing.
 * - **An item that already has a job** is at a supplier. There is nothing to
 *   place; the only honest instructions are the supplier's own resume and
 *   challenge-retry, and only when the job's `allowed_actions` says so. This
 *   class never offers a second placement for such an item, which is the
 *   defence against the double spend - not the 409 `RecordSupplierPlacement`
 *   would eventually answer with.
 *
 * Nothing here reports a placement. A successful send leaves an outbox row
 * `pending` and the alarm open, and the caller is told `queued` for that
 * reason (AGENTS.md Failures rule 3): saying "placed" would describe what was
 * attempted rather than what was written.
 */
final class ResendFulfillmentItem
{
    /**
     * The outbox status that means the publisher is mid-flight.
     *
     * Re-opening a claimed row would hand the same event to two senders. The
     * claim is itself a conditional update on `pending`
     * (`SignedOutboxDelivery::claim`), so a row in this state has a live run
     * behind it and the honest answer is to refuse.
     */
    private const IN_FLIGHT = 'processing';

    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
        private readonly EnqueueOrderPlacement $enqueuePlacement,
        private readonly ResumeItemDelivery $resumeDelivery,
        private readonly RetryItemChallenge $retryChallenge,
    ) {}

    /**
     * @return array{outcome: FulfillmentResendOutcome, action: string, previousEventStatus: ?string}
     */
    public function execute(
        User $actor,
        string $itemPublicId,
        FulfillmentResendAction $action,
        string $reasonCode,
        ?int $challengePosition = null,
        ?string $ipAddress = null,
        string $locale = 'en',
    ): array {
        if (! $actor->is_active || ! $actor->can(AdminPermission::FulfillmentAct->value)) {
            throw new AuthorizationException('This action requires fulfillment.act permission.');
        }

        // Read once to find the row the lock is named after, and then read it
        // again inside the lock. The second read is the one every decision is
        // made from: between a page render and this press an admin can cancel
        // the item or the poller can finish it, and deciding from the copy
        // loaded before the lock is how a press acts on a row that moved.
        /** @var OrderItem $item */
        $item = OrderItem::query()
            ->where('public_id', $itemPublicId)
            ->firstOrFail();

        // Rule 5: serialised per subject, and refused rather than queued. A
        // second press while the first is in flight must not wait its turn and
        // then send again - it must be told the first one is running. The hold
        // outlives the 60-second delivery timeout the publisher allows itself.
        //
        // Keyed on the order for a send, because the row a send re-opens is
        // the order's single `order.paid` outbox row: two items of one order
        // pressed together are one write, and per-item locks would let both
        // through. A supplier instruction is per item, and keyed that way.
        $subject = $action === FulfillmentResendAction::Send
            ? "fulfillment-resend:order:{$item->order_id}"
            : "fulfillment-resend:item:{$item->id}";
        $lock = Cache::lock($subject, 75);

        if (! $lock->get()) {
            return $this->result(FulfillmentResendOutcome::Busy, $action->value);
        }

        try {
            /** @var OrderItem $fresh */
            $fresh = OrderItem::query()
                ->with(['order', 'fulfillmentJob'])
                ->whereKey($item->id)
                ->firstOrFail();

            return $this->act($actor, $fresh, $action, $reasonCode, $challengePosition, $ipAddress, $locale);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{outcome: FulfillmentResendOutcome, action: string, previousEventStatus: ?string}
     */
    private function act(
        User $actor,
        OrderItem $item,
        FulfillmentResendAction $action,
        string $reasonCode,
        ?int $challengePosition,
        ?string $ipAddress,
        string $locale,
    ): array {
        $job = $item->fulfillmentJob;

        // Requested, before anything leaves and before the row is judged. A
        // reservation event rather than a result, per audit-logging.md: the
        // outcome is a second row, written from what actually happened - and
        // the pair has to hold for a press that was refused too, or the log
        // has a refusal nobody asked for. (A press refused for the lock never
        // reaches here: it touched nothing, and the press holding the lock is
        // writing the pair for that subject already.)
        $this->recordStaffAudit->execute($actor, $item, new StaffAuditEvent(
            action: 'fulfillment.resend_requested',
            metadata: [
                'order_number' => (string) $item->order->order_number,
                'order_item_public_id' => (string) $item->public_id,
                'action' => $action->value,
                'reason_code' => $reasonCode,
                'supplier' => $this->currentSupplier($item, $job),
            ],
            ipAddress: $ipAddress,
        ));

        if (! $this->isActionable($item)) {
            return $this->audited($actor, $item, $this->result(FulfillmentResendOutcome::NotActionable, $action->value), $reasonCode, $ipAddress);
        }

        $write = match ($action) {
            FulfillmentResendAction::Send => fn (): array => $this->send($item, $job),
            FulfillmentResendAction::Resume => fn (): array => $this->supplierAction($item, $job, SupplierAction::Resume, $locale),
            FulfillmentResendAction::RetryChallenge => fn (): array => $this->supplierAction($item, $job, SupplierAction::RetryChallenge, $locale, $challengePosition),
        };

        // Rule 4: a send and the row that records it are one write. Nothing
        // leaves the process between them, so a crash must not be able to
        // leave a queued outbox row with no row saying who queued it.
        //
        // A supplier instruction cannot be wrapped the same way: it leaves the
        // process, and a transaction held across a supplier call holds its
        // locks for as long as the supplier takes to answer.
        return $action === FulfillmentResendAction::Send
            ? DB::transaction(fn (): array => $this->audited($actor, $item, $write(), $reasonCode, $ipAddress))
            : $this->audited($actor, $item, $write(), $reasonCode, $ipAddress);
    }

    /**
     * Re-opens the order's placement request, or writes one if none exists.
     *
     * @return array{outcome: FulfillmentResendOutcome, action: string, previousEventStatus: ?string}
     */
    private function send(OrderItem $item, ?FulfillmentJob $job): array
    {
        // An item at a supplier is never re-sent. The control does not offer
        // it, and this is the gate behind the control.
        if ($job instanceof FulfillmentJob) {
            return $this->result(FulfillmentResendOutcome::NotActionable, 'send');
        }

        /** @var IntegrationEvent|null $event */
        $event = IntegrationEvent::query()
            ->where('event_type', 'order.paid')
            ->where('aggregate_id', (string) $item->order->public_id)
            ->first();

        if (! $event instanceof IntegrationEvent) {
            // Nothing was ever queued for this order. Writing the row is what
            // should have happened when it was paid, and the unique key makes
            // a concurrent writer a replay rather than a duplicate.
            try {
                $created = $this->enqueuePlacement->execute($item->order);
            } catch (UniqueConstraintViolationException) {
                return $this->result(FulfillmentResendOutcome::Queued, 'send');
            }

            // `EnqueueOrderPlacement` writes nothing when the order owes no
            // placement. `isActionable()` has already established that this
            // item does, so a null here is a state that changed underneath us.
            return $created === null
                ? $this->result(FulfillmentResendOutcome::NotActionable, 'send')
                : $this->result(FulfillmentResendOutcome::Queued, 'send');
        }

        $previous = (string) $event->status;

        if ($previous === self::IN_FLIGHT) {
            return $this->result(FulfillmentResendOutcome::InFlight, 'send', $previous);
        }

        // Guarded on the status this read saw, so two concurrent re-opens
        // cannot both claim to have done it: one affects a row, the other
        // affects none and is told so. Resetting `attempts` grants a fresh
        // retry budget - the publisher's selection skips rows at the ceiling,
        // so keeping the old count would make this a silent no-op - and
        // clearing `last_error` is what stops `PlacementBlockers` reporting a
        // reason the store is no longer stuck on.
        $reopened = IntegrationEvent::query()
            ->whereKey($event->id)
            ->where('status', $previous)
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'last_error' => null,
                'processed_at' => null,
                'updated_at' => now(),
            ]);

        return $reopened === 1
            ? $this->result(FulfillmentResendOutcome::Queued, 'send', $previous)
            : $this->result(FulfillmentResendOutcome::Refused, 'send', $previous);
    }

    /**
     * Hands a supplier instruction to the action that owns it.
     *
     * Both customer actions are reused rather than reimplemented: they carry
     * the `ResolveActionableItem` gate, the supplier-reference lookup and the
     * refusal handling, and an Admin-only copy of any of that would be a
     * second truth about what a stuck job allows.
     *
     * Only the outcome is kept. Those actions answer with the customer's
     * `ItemTracking` payload, which is built for a different reader and has no
     * business in an admin response.
     *
     * @return array{outcome: FulfillmentResendOutcome, action: string, previousEventStatus: ?string}
     */
    private function supplierAction(
        OrderItem $item,
        ?FulfillmentJob $job,
        SupplierAction $action,
        string $locale,
        ?int $challengePosition = null,
    ): array {
        if (! $job instanceof FulfillmentJob || ! in_array($action, $job->allowedActions(), true)) {
            return $this->result(FulfillmentResendOutcome::NotActionable, $action->value);
        }

        try {
            $answer = $action === SupplierAction::Resume
                ? $this->resumeDelivery->execute($item, $locale)
                : $this->retryChallenge->execute($item, $challengePosition ?? 0, $locale);
        } catch (AuthorizationException) {
            // The gate behind the button disagreed with the button, which
            // means the row moved between the page render and the press.
            return $this->result(FulfillmentResendOutcome::NotActionable, $action->value);
        }

        if ($answer['status'] !== 'accepted') {
            return $this->result(FulfillmentResendOutcome::Refused, $action->value);
        }

        return $this->result(
            $action === SupplierAction::Resume
                ? FulfillmentResendOutcome::ResumeAccepted
                : FulfillmentResendOutcome::RetryAccepted,
            $action->value,
        );
    }

    /**
     * Whether this item is one an operator may still act on.
     *
     * The same three facts the alarm sweep and the publisher read, in the same
     * order: an automated service, a paid order, and neither the item nor its
     * order finished. A booster item has no supplier to send it to, and a
     * cancelled or refunded item must never reach one.
     */
    private function isActionable(OrderItem $item): bool
    {
        if (! in_array($item->service_type, [ServiceType::Coins, ServiceType::Sbc], true)) {
            return false;
        }

        if ($item->order->paid_at === null) {
            return false;
        }

        if (in_array($item->status, AwaitingPlacement::CLOSED, true)) {
            return false;
        }

        return ! in_array($item->order->status, [
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::Refunded,
        ], true);
    }

    /**
     * Writes the truthful result row and hands the outcome back.
     *
     * @param  array{outcome: FulfillmentResendOutcome, action: string, previousEventStatus: ?string}  $result
     * @return array{outcome: FulfillmentResendOutcome, action: string, previousEventStatus: ?string}
     */
    private function audited(User $actor, OrderItem $item, array $result, string $reasonCode, ?string $ipAddress): array
    {
        $dispatched = $result['outcome']->dispatched();

        $this->recordStaffAudit->execute($actor, $item, new StaffAuditEvent(
            action: $dispatched ? 'fulfillment.resend_dispatched' : 'fulfillment.resend_refused',
            metadata: [
                'order_number' => (string) $item->order->order_number,
                'order_item_public_id' => (string) $item->public_id,
                'action' => $result['action'],
                'reason_code' => $reasonCode,
                'supplier' => $this->currentSupplier($item, $item->fulfillmentJob),
                // The store's own word for where the outbox row was, so the
                // widening this action performs - a `processed` row re-opened -
                // is visible afterwards rather than erased by it.
                'previous_event_status' => $result['previousEventStatus'],
                'outcome' => $result['outcome']->value,
            ],
            ipAddress: $ipAddress,
        ));

        return $result;
    }

    /**
     * Who is actually holding this item, for the audit row.
     *
     * The job's `supplier` column mirrors the FIRST placement, and an SBC item
     * funded by one supplier can be solved at another - so a log that reads
     * the mirror names the wrong supplier for exactly the presses a challenge
     * retry makes. Read from the placement of the job's current phase, the way
     * the screen itself reads it, and fall back to the mirror only when there
     * is no placement row to read.
     */
    private function currentSupplier(OrderItem $item, ?FulfillmentJob $job): ?string
    {
        if (! $job instanceof FulfillmentJob) {
            return null;
        }

        $placement = $job->placements()
            ->when(
                $job->delivery_phase !== null,
                fn ($query) => $query->where('delivery_phase', $job->delivery_phase->value),
            )
            ->latest('id')
            ->first();

        // A placement always names its supplier; the job's mirror may not.
        return $placement?->supplier->value ?? $job->supplier?->value;
    }

    /**
     * @return array{outcome: FulfillmentResendOutcome, action: string, previousEventStatus: ?string}
     */
    private function result(FulfillmentResendOutcome $outcome, string $action, ?string $previousEventStatus = null): array
    {
        return [
            'outcome' => $outcome,
            'action' => $action,
            'previousEventStatus' => $previousEventStatus,
        ];
    }
}
