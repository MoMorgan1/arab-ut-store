<?php

namespace Database\Factories;

use App\Enums\FulfillmentStatus;
use App\Models\FulfillmentJob;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FulfillmentJob> */
class FulfillmentJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'status' => FulfillmentStatus::Pending,
            'idempotency_key' => (string) str()->ulid(),
            'attempt_count' => 0,
        ];
    }
}
