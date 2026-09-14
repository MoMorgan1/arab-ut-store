<?php

namespace App\Suppliers;

use App\Enums\Supplier;
use Carbon\CarbonImmutable;

/**
 * One supplier status read, carrying the supplier's payload untouched.
 *
 * Both suppliers are normalised into the FFT payload shape, but the values are
 * not interpreted here. Translating this into a customer-facing state belongs
 * to the layer above.
 */
final readonly class RawSupplierObservation
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Supplier $supplier,
        public string $supplierOrderId,
        public array $payload,
        public CarbonImmutable $fetchedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'supplier' => $this->supplier->value,
            'supplier_order_id' => $this->supplierOrderId,
            'payload' => $this->payload,
            'fetched_at' => $this->fetchedAt->toIso8601String(),
        ];
    }
}
