<?php

namespace App\Actions\Fulfillment;

use App\Account\Presenters\ItemTracking;
use App\Enums\SupplierAction;
use App\Models\OrderItem;
use App\Suppliers\Exceptions\SupplierNotConfigured;
use App\Suppliers\Exceptions\SupplierUnavailable;
use App\Suppliers\SupplierRegistry;
use Illuminate\Support\Facades\Log;

final class ResumeItemDelivery
{
    public function __construct(
        private readonly SupplierRegistry $registry,
        private readonly ResolveActionableItem $resolveActionableItem,
    ) {}

    /**
     * Asks the supplier to resume a stopped or interrupted delivery.
     *
     * @return array{tracking: array<string, mixed>|null, status: string}
     */
    public function execute(OrderItem $item, string $locale): array
    {
        $resolved = $this->resolveActionableItem->for($item, SupplierAction::Resume);

        try {
            $client = $this->registry->for($resolved->supplier);
            $result = $client->resume($resolved->supplierOrderId);
        } catch (SupplierUnavailable) {
            return $this->response($item, $locale, 'refused');
        } catch (SupplierNotConfigured $exception) {
            Log::error('Supplier not configured while resuming fulfillment job {job_id}', [
                'job_id' => $resolved->job->id,
                'supplier' => $resolved->supplier->value,
                'reason' => $exception->reason,
            ]);

            return $this->response($item, $locale, 'refused');
        }

        return $this->response($item, $locale, $result->accepted ? 'accepted' : 'refused');
    }

    /**
     * @return array{tracking: array<string, mixed>|null, status: string}
     */
    private function response(OrderItem $item, string $locale, string $status): array
    {
        return [
            'tracking' => ItemTracking::for($item, $locale),
            'status' => $status,
        ];
    }
}
