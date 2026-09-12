<?php

namespace App\Suppliers\Exceptions;

use App\Enums\Supplier;
use RuntimeException;
use Throwable;

/**
 * A supplier call did not produce a usable answer.
 *
 * The message never carries credentials or a response body; the machine
 * readable {@see self::$reason} is what callers branch on.
 */
final class SupplierUnavailable extends RuntimeException
{
    public function __construct(
        public readonly Supplier $supplier,
        public readonly string $supplierOrderId,
        public readonly ?int $httpStatus,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Supplier [%s] call for order [%s] failed (%s%s).',
                $supplier->value,
                $supplierOrderId,
                $reason,
                $httpStatus === null ? '' : ', HTTP '.$httpStatus,
            ),
            0,
            $previous,
        );
    }
}
