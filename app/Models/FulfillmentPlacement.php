<?php

namespace App\Models;

use App\Enums\DeliveryPhase;
use App\Enums\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DeliveryPhase $delivery_phase
 * @property Supplier $supplier
 *                              The challenge id column is json, so its contents are whatever was written -
 *                              not necessarily a list of strings. Annotating it as mixed keeps the
 *                              defensive read in challengeIds() meaningful instead of making PHPStan call
 *                              it dead.
 * @property array<int, mixed>|null $supplier_challenge_ids
 */
class FulfillmentPlacement extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivery_phase' => DeliveryPhase::class,
            'supplier' => Supplier::class,
            'supplier_challenge_ids' => 'array',
            'placed_at' => 'immutable_datetime',
        ];
    }

    /**
     * The stored challenge identifiers, or an empty list when none are recorded.
     *
     * @return list<string>
     */
    public function challengeIds(): array
    {
        $stored = $this->supplier_challenge_ids;

        if (! is_array($stored)) {
            return [];
        }

        $ids = [];

        foreach ($stored as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return BelongsTo<FulfillmentJob, $this> */
    public function fulfillmentJob(): BelongsTo
    {
        return $this->belongsTo(FulfillmentJob::class);
    }
}
