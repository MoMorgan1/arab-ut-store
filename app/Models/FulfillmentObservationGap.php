<?php

namespace App\Models;

use App\Enums\DeliveryPhase;
use App\Enums\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One measured interval between two landed observations of the same job.
 *
 * The poller already knew this number and threw it away: `observed_at` is a
 * single column overwritten on every successful read, so the interval between
 * two readings exists for exactly as long as it takes to write the second one
 * over the first. This table is that instant caught and kept.
 *
 * It is a measurement, not state. Nothing reads it to decide anything - the
 * stall alarm reads its thresholds from configuration and never touches this
 * table - and nothing customer-facing has any business here. It exists so the
 * phase cadence table can eventually be written from what happened rather than
 * from what somebody guessed.
 *
 * @property DeliveryPhase|null $delivery_phase
 * @property Supplier|null $supplier
 * @property int $gap_seconds
 * @property bool $state_changed
 * @property CarbonImmutable $observed_at
 */
class FulfillmentObservationGap extends DomainModel
{
    /**
     * One instant, written once. See the migration.
     */
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivery_phase' => DeliveryPhase::class,
            'supplier' => Supplier::class,
            'gap_seconds' => 'integer',
            'state_changed' => 'boolean',
            'observed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<FulfillmentJob, $this> */
    public function fulfillmentJob(): BelongsTo
    {
        return $this->belongsTo(FulfillmentJob::class);
    }
}
