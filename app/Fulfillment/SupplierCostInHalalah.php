<?php

namespace App\Fulfillment;

use App\Models\PriceRun;

/**
 * What a supplier has charged for a shipment, in the store's own unit.
 *
 * Both suppliers report cost in euros: FFT as `toPay` on every status read,
 * UTT as `moneySpent` plus `privateMoneySpent` (summed by the client into
 * `_costEur`). v14 converted the figure to dollars at the pricing workflow's
 * EUR/USD ratio and wrote it to a sheet column; the store converts the same
 * way, on to riyals at the peg, and keeps it on `actual_cost_halalah`. The
 * ratio comes from the newest applied pricing run - the basis the budget was
 * built on, so the two can be compared. With no run to read, nothing is
 * written: a cost we cannot convert is not a cost we should invent.
 */
final class SupplierCostInHalalah
{
    /** The riyal has been pegged to the dollar at this rate since 1986. */
    private const float SAR_PER_USD = 3.75;

    /**
     * @param  array<string, mixed>  $rawPayload  the supplier's status payload, before masking
     */
    public function fromObservation(array $rawPayload): ?int
    {
        $eur = ($rawPayload['_supplier'] ?? null) === 'UTT'
            ? ($rawPayload['_costEur'] ?? null)
            : ($rawPayload['toPay'] ?? null);

        if (! is_numeric($eur) || (float) $eur <= 0) {
            return null;
        }

        $ratio = $this->usdPerEur();

        if ($ratio === null) {
            return null;
        }

        return (int) round((float) $eur * $ratio * self::SAR_PER_USD * 100);
    }

    private function usdPerEur(): ?float
    {
        $run = PriceRun::query()
            ->where('status', 'applied')
            ->orderByDesc('id')
            ->first();

        $ratio = $run instanceof PriceRun ? ($run->payload['observations']['ratioEuroUsd'] ?? null) : null;

        return is_numeric($ratio) && (float) $ratio > 0 ? (float) $ratio : null;
    }
}
