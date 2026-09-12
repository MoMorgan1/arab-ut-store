<?php

namespace App\Actions\Fulfillment;

use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
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
     * @return array{outcome: 'recorded'|'replayed'|'unknown_item'|'not_automated'|'unpaid'|'supplier_reference_conflict'|'item_conflict'|'placement_conflict', jobPublicId: string|null}
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
            // saw no conflict. The loser is told to retry, and the retry then
            // reads the winner and resolves to the idempotent replay or the
            // precise conflict.
            return ['outcome' => 'placement_conflict', 'jobPublicId' => null];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{outcome: 'recorded'|'replayed'|'unknown_item'|'not_automated'|'unpaid'|'supplier_reference_conflict'|'item_conflict'|'placement_conflict', jobPublicId: string|null}
     */
    private function record(array $payload): array
    {
        $publicId = (string) $payload['order_item_public_id'];
        $supplier = Supplier::from((string) $payload['supplier']);
        $reference = (string) $payload['supplier_order_id'];

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

        if ($order->paid_at === null) {
            return self::result('unpaid');
        }

        /** @var FulfillmentJob|null $job */
        $job = FulfillmentJob::query()
            ->where('order_item_id', $item->id)
            ->lockForUpdate()
            ->first();

        if ($job !== null
            && $job->supplier === $supplier
            && $job->supplier_order_id === $reference) {
            // An identical retry is a true no-op: a job that has since moved
            // on, failed, or accumulated poll errors must not be reopened or
            // reset by a stale placement retry.
            return self::result('replayed', $job);
        }

        if ($job !== null
            && ($job->supplier !== null && $job->supplier !== $supplier
                || $job->supplier_order_id !== null)) {
            return self::result('item_conflict');
        }

        $boundElsewhere = FulfillmentJob::query()
            ->where('supplier', $supplier->value)
            ->where('supplier_order_id', $reference)
            ->where('order_item_id', '!=', $item->id)
            ->lockForUpdate()
            ->exists();

        if ($boundElsewhere) {
            return self::result('supplier_reference_conflict');
        }

        $placement = [
            'status' => FulfillmentStatus::InProgress,
            'supplier' => $supplier,
            'supplier_order_id' => $reference,
            'next_poll_at' => now(),
            'last_error_code' => null,
            'last_error' => null,
        ];

        if (isset($payload['delivery_phase'])) {
            $placement['delivery_phase'] = DeliveryPhase::from((string) $payload['delivery_phase']);
        }

        if ($job !== null) {
            $job->forceFill($placement)->save();

            return self::result('recorded', $job);
        }

        $job = FulfillmentJob::query()->create($placement + [
            'order_item_id' => $item->id,
            'idempotency_key' => 'fulfillment-placement:'.$publicId,
        ]);

        return self::result('recorded', $job);
    }

    /**
     * @param  'recorded'|'replayed'|'unknown_item'|'not_automated'|'unpaid'|'supplier_reference_conflict'|'item_conflict'|'placement_conflict'  $outcome
     * @return array{outcome: 'recorded'|'replayed'|'unknown_item'|'not_automated'|'unpaid'|'supplier_reference_conflict'|'item_conflict'|'placement_conflict', jobPublicId: string|null}
     */
    private static function result(string $outcome, ?FulfillmentJob $job = null): array
    {
        return [
            'outcome' => $outcome,
            'jobPublicId' => $job instanceof FulfillmentJob ? (string) $job->public_id : null,
        ];
    }
}
