<?php

namespace App\Actions\Fulfillment;

use App\Account\Presenters\ItemTracking;
use App\Enums\SupplierAction;
use App\Models\FulfillmentJob;
use App\Models\OrderItem;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Re-authorises a customer's self-service action before anything is sent.
 *
 * The client never decides what a customer may do to their own stuck job: the
 * button set is a convenience rendered from the allowed-action column, and this
 * is the gate that stands behind it. It fails closed for a job that has no
 * supplier reference yet, for an action the job does not list, and for a
 * terminal order or item, because none of those is something a customer can act
 * on.
 */
final class ResolveActionableItem
{
    /**
     * The item's fulfillment job and supplier reference, ready to act on.
     *
     * @throws AuthorizationException
     */
    public function for(OrderItem $item, SupplierAction $action): ActionableItem
    {
        $job = $item->relationLoaded('fulfillmentJob')
            ? $item->fulfillmentJob
            : $item->fulfillmentJob()->first();

        if (! $job instanceof FulfillmentJob
            || $job->supplier === null
            || ($job->supplier_order_id ?? '') === '') {
            throw new AuthorizationException('This item cannot be acted on yet.');
        }

        if (! in_array($action, $job->allowedActions(), true)) {
            throw new AuthorizationException('This action is not available for this item.');
        }

        if (ItemTracking::terminalPresentation($item) !== null) {
            throw new AuthorizationException('A finished order cannot be acted on.');
        }

        if (! $item->relationLoaded('fulfillmentJob')) {
            $item->setRelation('fulfillmentJob', $job);
        }

        return new ActionableItem(
            job: $job,
            supplier: $job->supplier,
            supplierOrderId: (string) $job->supplier_order_id,
        );
    }
}
