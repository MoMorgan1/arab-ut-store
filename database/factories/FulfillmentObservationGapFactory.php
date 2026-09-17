<?php

namespace Database\Factories;

use App\Enums\DeliveryPhase;
use App\Enums\PollBand;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentObservationGap;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FulfillmentObservationGap> */
class FulfillmentObservationGapFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fulfillment_job_id' => FulfillmentJob::factory(),
            'delivery_phase' => DeliveryPhase::Coins,
            'supplier' => Supplier::Fft,
            'band' => PollBand::Background,
            'gap_seconds' => 180,
            'moved' => false,
            'poll_failure_count' => 0,
            'coins_delivered' => null,
            'squads_done' => null,
            'solves_done' => null,
            'observed_at' => now(),
        ];
    }
}
