<?php

namespace App\Suppliers\Exceptions;

use App\Enums\Supplier;
use RuntimeException;

/**
 * A supplier cannot be called because its configuration is missing or unsafe.
 *
 * This fails closed before any request leaves the application, rather than
 * half-working with a blank credential.
 */
final class SupplierNotConfigured extends RuntimeException
{
    public function __construct(
        public readonly Supplier $supplier,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Supplier [%s] is not configured (%s).',
            $supplier->value,
            $reason,
        ));
    }
}
