<?php

namespace Database\Factories;

use App\Enums\DeliveryPhase;
use App\Enums\Supplier;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FulfillmentPlacement> */
class FulfillmentPlacementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fulfillment_job_id' => FulfillmentJob::factory(),
            'delivery_phase' => DeliveryPhase::Coins,
            'supplier' => Supplier::Fft,
            'supplier_order_id' => 'FFT-'.str()->ulid(),
            'idempotency_key' => 'fulfillment-placement:'.str()->ulid().':coins',
            'placed_at' => now(),
        ];
    }
}
