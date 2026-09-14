<?php

namespace App\Actions\Fulfillment;

use App\Account\Presenters\ItemTracking;
use App\Enums\DeliveryPhase;
use App\Enums\SupplierAction;
use App\Models\FulfillmentPlacement;
use App\Models\OrderItem;
use App\Suppliers\Exceptions\SupplierNotConfigured;
use App\Suppliers\Exceptions\SupplierUnavailable;
use App\Suppliers\SupplierRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

final class RetryItemChallenge
{
    public function __construct(
        private readonly SupplierRegistry $registry,
        private readonly ResolveActionableItem $resolveActionableItem,
    ) {}

    /**
     * Asks the supplier to retry a failed challenge solve.
     *
     * The client sends the challenge's position on the card, not a raw supplier id, so
     * the position is resolved against the placement's stored challenge ids. That is also
     * the ownership check: a crafted request cannot retry another customer's challenge
     * through our own credentials, because only a challenge recorded on this job's
     * placement can be named by a position.
     *
     * @return array{tracking: array<string, mixed>|null, status: string}
     */
    public function execute(OrderItem $item, mixed $target, string $locale): array
    {
        $resolved = $this->resolveActionableItem->for($item, SupplierAction::RetryChallenge);

        /** @var FulfillmentPlacement|null $placement */
        $placement = $resolved->job->relationLoaded('placements')
            ? $resolved->job->placements->first(fn (FulfillmentPlacement $p): bool => $p->delivery_phase === DeliveryPhase::Challenge)
            : $resolved->job->placements()->where('delivery_phase', DeliveryPhase::Challenge->value)->latest('id')->first();

        $challengeIds = $placement?->challengeIds() ?? [];

        if (! is_int($target) || $target < 0 || $target >= count($challengeIds)) {
            throw new AuthorizationException('This challenge does not belong to the order.');
        }

        $challengeSupplier = $placement->supplier ?? $resolved->supplier;
        $challengeId = $challengeIds[$target];
        $supplierOrderId = (string) ($placement->supplier_order_id ?? $resolved->supplierOrderId);

        try {
            $client = $this->registry->for($challengeSupplier);
            $result = $client->retryChallenge($supplierOrderId, $challengeId);
        } catch (SupplierUnavailable) {
            return $this->response($item, $locale, 'refused');
        } catch (SupplierNotConfigured $exception) {
            Log::error('Supplier not configured while retrying a challenge for fulfillment job {job_id}', [
                'job_id' => $resolved->job->id,
                'supplier' => $challengeSupplier->value,
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
