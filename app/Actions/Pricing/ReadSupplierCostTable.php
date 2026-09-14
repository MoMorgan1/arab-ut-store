<?php

namespace App\Actions\Pricing;

use App\Models\PriceRun;
use App\ValueObjects\Pricing\SupplierCostTable;
use DomainException;

/**
 * The supplier cost table from the newest applied pricing run.
 *
 * Read at send time, never at order time: v14 read the sheet when it placed,
 * and a placement retried hours later should spend against the market the
 * suppliers are in now rather than the one they were in when the customer
 * paid. `PrunePricingHistory` keeps the newest applied run whatever its age,
 * so a stalled pricing workflow leaves the last table that worked in place.
 */
final class ReadSupplierCostTable
{
    /**
     * @throws DomainException when no applied run exists or the newest one
     *                         carries no cost table
     */
    public function execute(): SupplierCostTable
    {
        $run = PriceRun::query()
            ->where('status', 'applied')
            ->orderByDesc('id')
            ->first();

        if (! $run instanceof PriceRun) {
            throw new DomainException('No applied Coins pricing run exists.');
        }

        $observations = $run->payload['observations'] ?? null;

        return SupplierCostTable::fromObservations(
            (int) $run->pricing_version,
            is_array($observations) ? $observations : [],
        );
    }
}
