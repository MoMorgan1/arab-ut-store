<?php

namespace App\Actions\Fulfillment;

use App\Enums\Supplier;
use App\Models\FulfillmentJob;

/**
 * A fulfillment job that has passed action authorisation, with the non-null
 * supplier reference it carries. The resolver guarantees these are present, so
 * callers hold the job and its supplier without re-checking either.
 */
final readonly class ActionableItem
{
    public function __construct(
        public FulfillmentJob $job,
        public Supplier $supplier,
        public string $supplierOrderId,
    ) {}
}
