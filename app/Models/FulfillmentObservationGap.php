<?php

namespace App\Models;

use App\Enums\DeliveryPhase;
use App\Enums\PollBand;
use App\Enums\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 * Not a DomainModel, and the only other model in this application that is not:
 * DomainModel exists to give a row a public ULID, and nothing outside the
 * database ever names one of these.
 *
 * @property DeliveryPhase|null $delivery_phase
 * @property Supplier|null $supplier
 * @property PollBand $band
 * @property int $gap_seconds
 * @property bool $moved
 * @property int $poll_failure_count
 * @property int|null $coins_delivered
 * @property int|null $squads_done
 * @property int|null $solves_done
 * @property CarbonImmutable $observed_at
 */
class FulfillmentObservationGap extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * One instant, written once. See the migration.
     */
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivery_phase' => DeliveryPhase::class,
            'supplier' => Supplier::class,
            'band' => PollBand::class,
            'gap_seconds' => 'integer',
            'moved' => 'boolean',
            'poll_failure_count' => 'integer',
            'coins_delivered' => 'integer',
            'squads_done' => 'integer',
            'solves_done' => 'integer',
            'observed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<FulfillmentJob, $this> */
    public function fulfillmentJob(): BelongsTo
    {
        return $this->belongsTo(FulfillmentJob::class);
    }
}
