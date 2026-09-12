<?php

namespace App\Suppliers;

use App\Enums\Supplier;

/**
 * Picks the client for a supplier. Constructed by the container, so callers
 * depend only on this class rather than on both clients.
 */
final class SupplierRegistry
{
    public function __construct(
        private readonly FftClient $fft,
        private readonly UttClient $utt,
    ) {}

    public function for(Supplier $supplier): SupplierClient
    {
        return match ($supplier) {
            Supplier::Fft => $this->fft,
            Supplier::Utt => $this->utt,
        };
    }
}
