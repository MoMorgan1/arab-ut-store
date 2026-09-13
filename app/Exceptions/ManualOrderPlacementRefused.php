<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * `RecordSupplierPlacement` refused the reference a staff member pasted.
 *
 * Carries the outcome so the form can say which of its fields is wrong. Before
 * this existed the refusal was a bare `RuntimeException`, which reached the
 * staff member as a 500 and lost every field they had filled in - for what is
 * ordinary mistyping, not an outside failure.
 *
 * Extends `RuntimeException` because the action's contract is that a placement
 * it cannot record aborts the whole order, and callers that only care about
 * that still catch what they always caught.
 */
final class ManualOrderPlacementRefused extends RuntimeException
{
    public function __construct(
        /** @var string One of `RecordSupplierPlacement`'s non-success outcomes. */
        public readonly string $outcome,
        public readonly string $supplierOrderId,
    ) {
        parent::__construct(sprintf(
            'The supplier reference %s could not be recorded: %s.',
            $supplierOrderId,
            $outcome,
        ));
    }
}
