<?php

namespace App\Actions\Fulfillment;

use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class RecordSupplierPlacement
{
    /**
     * The services a supplier bot delivers, stated positively and locally.
     *
     * Deliberately not ServiceType::isManual(): that enum lists only Rivals
     * and FUT Champions as manual, so its complement would accept Objectives,
     * a service no supplier delivers. A false rejection is a visible 422; a
     * false acceptance silently binds a reference to work nobody is doing.
     *
     * @var list<ServiceType>
     */
    private const AUTOMATED_SERVICES = [ServiceType::Coins, ServiceType::Sbc];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{outcome: 'recorded'|'replayed'|'unknown_item'|'not_automated'|'service_has_no_challenge'|'supplier_cannot_solve_challenges'|'unpaid'|'supplier_reference_conflict'|'item_conflict'|'placement_conflict', jobPublicId: string|null}
     */
    public function execute(array $payload): array
    {
        try {
            return DB::transaction(
                fn (): array => $this->record($payload),
                attempts: 3,
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent request won a unique index race after both reads
            // saw no conflict. The database constraint is the real authority
            // here; the application-level checks above exist only to return a
            // friendlier 409, so this catch is the backstop for the race they
            // cannot close, not dead code. The loser is told to retry, and the
            // retry then reads the winner and resolves to the idempotent
            // replay or the precise conflict.
            return ['outcome' => 'placement_conflict', 'jobPublicId' => null];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{outcome: 'recorded'|'replayed'|'unknown_item'|'not_automated'|'service_has_no_challenge'|'supplier_cannot_solve_challenges'|'unpaid'|'supplier_reference_conflict'|'item_conflict'|'placement_conflict', jobPublicId: string|null}
     */
    private function record(array $payload): array
    {
        $publicId = (string) $payload['order_item_public_id'];
        $supplier = Supplier::from((string) $payload['supplier']);
        $reference = (string) $payload['supplier_order_id'];
        $phase = DeliveryPhase::from((string) $payload['delivery_phase']);

        /** @var OrderItem|null $item */
        $item = OrderItem::query()->where('public_id', $publicId)->first();

        if ($item === null) {
            return self::result('unknown_item');
        }

        // Lock the order before its item, matching the order-first lock order
        // used by the admin transition and the payment reconciliation, so a
        // placement cannot deadlock against either.
        /** @var Order $order */
        $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->sole();

        /** @var OrderItem $item */
        $item = OrderItem::query()->whereKey($item->getKey())->lockForUpdate()->sole();

        if (! in_array($item->service_type, self::AUTOMATED_SERVICES, true)) {
            return self::result('not_automated');
        }

        // A challenge phase only exists under an SBC item, and only FFT solves
        // challenges. Refuse the impossible pairings before payment state:
        // they can never become valid by waiting.
        if ($phase === DeliveryPhase::Challenge && $item->service_type !== ServiceType::Sbc) {
            return self::result('service_has_no_challenge');
        }

        if ($phase === DeliveryPhase::Challenge && ! $supplier->handlesChallenges()) {
            return self::result('supplier_cannot_solve_challenges');
        }

        if ($order->paid_at === null) {
            return self::result('unpaid');
        }

        /** @var FulfillmentJob|null $job */
        $job = FulfillmentJob::query()
            ->where('order_item_id', $item->id)
            ->lockForUpdate()
            ->first();

        if ($job instanceof FulfillmentJob) {
            $existing = FulfillmentPlacement::query()
                ->where('fulfillment_job_id', $job->id)
                ->where('delivery_phase', $phase->value)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof FulfillmentPlacement) {
                // The same phase with the same exact report is a true no-op: a
                // job that has since moved on, failed, or accumulated poll
                // errors must not be reopened or reset by a stale retry.
                return $existing->supplier === $supplier
                    && $existing->supplier_order_id === $reference
                        ? self::result('replayed', $job)
                        : self::result('item_conflict');
            }

            // Only a job with no placements at all can carry a hand-built or
            // pre-migration mirror; once a placement exists, the mirror is
            // just the first placement's advertisement and never blocks a
            // later phase. A mirror missing either identity column is
            // half-written and unbound: treating it as bound compares a null
            // reference against every incoming one and refuses forever.
            $mirrorBound = ! $job->placements()->exists()
                && $job->supplier !== null
                && $job->supplier_order_id !== null;

            if ($mirrorBound
                && ($job->supplier !== $supplier
                    || $job->supplier_order_id !== $reference
                    || ($job->delivery_phase !== null && $job->delivery_phase !== $phase))) {
                return self::result('item_conflict');
            }
        }

        // A supplier reference identifies one supplier-side order for exactly
        // one item and phase, so it can never be recorded on a second
        // placement, whatever that placement is for.
        $boundElsewhere = FulfillmentPlacement::query()
            ->where('supplier', $supplier->value)
            ->where('supplier_order_id', $reference)
            ->lockForUpdate()
            ->exists();

        if ($boundElsewhere) {
            return self::result('supplier_reference_conflict');
        }

        if (! $job instanceof FulfillmentJob) {
            $job = FulfillmentJob::query()->create([
                'order_item_id' => $item->id,
                'status' => FulfillmentStatus::InProgress,
                'supplier' => $supplier,
                'supplier_order_id' => $reference,
                'delivery_phase' => $phase,
                'next_poll_at' => now(),
                'last_error_code' => null,
                'last_error' => null,
                'idempotency_key' => 'fulfillment-placement:'.$publicId,
            ]);
        } elseif ($job->supplier === null || $job->supplier_order_id === null) {
            // The job holds no complete first-placement identity yet, whether
            // it is untouched or half-written; adopt this one's identity.
            // Later phases leave the mirror alone: it advertises the first
            // placement, while the placements table holds the full record.
            $job->forceFill([
                'status' => FulfillmentStatus::InProgress,
                'supplier' => $supplier,
                'supplier_order_id' => $reference,
                'delivery_phase' => $phase,
                'next_poll_at' => now(),
                'last_error_code' => null,
                'last_error' => null,
            ])->save();
        } elseif ($job->delivery_phase === null) {
            // A legacy partial mirror (supplier and reference, no phase).
            $job->forceFill(['delivery_phase' => $phase])->save();
        }

        FulfillmentPlacement::query()->create([
            'fulfillment_job_id' => $job->id,
            'delivery_phase' => $phase,
            'supplier' => $supplier,
            'supplier_order_id' => $reference,
            'idempotency_key' => 'fulfillment-placement:'.$publicId.':'.$phase->value,
            'placed_at' => now(),
        ]);

        return self::result('recorded', $job);
    }

    /**
     * @param  'recorded'|'replayed'|'unknown_item'|'not_automated'|'service_has_no_challenge'|'supplier_cannot_solve_challenges'|'unpaid'|'supplier_reference_conflict'|'item_conflict'|'placement_conflict'  $outcome
     * @return array{outcome: 'recorded'|'replayed'|'unknown_item'|'not_automated'|'service_has_no_challenge'|'supplier_cannot_solve_challenges'|'unpaid'|'supplier_reference_conflict'|'item_conflict'|'placement_conflict', jobPublicId: string|null}
     */
    private static function result(string $outcome, ?FulfillmentJob $job = null): array
    {
        return [
            'outcome' => $outcome,
            'jobPublicId' => $job instanceof FulfillmentJob ? (string) $job->public_id : null,
        ];
    }
}
