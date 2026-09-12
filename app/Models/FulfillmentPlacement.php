<?php

namespace App\Models;

use App\Enums\DeliveryPhase;
use App\Enums\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DeliveryPhase $delivery_phase
 * @property Supplier $supplier
 */
class FulfillmentPlacement extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivery_phase' => DeliveryPhase::class,
            'supplier' => Supplier::class,
            'placed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<FulfillmentJob, $this> */
    public function fulfillmentJob(): BelongsTo
    {
        return $this->belongsTo(FulfillmentJob::class);
    }
}
