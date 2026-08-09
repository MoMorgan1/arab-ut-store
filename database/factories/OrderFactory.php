<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'order_number' => 'UT-'.fake()->unique()->numerify('########'),
            'status' => OrderStatus::PendingPayment,
            'locale' => 'ar',
            'currency' => 'SAR',
            'subtotal_halalah' => 10_000,
            'discount_halalah' => 0,
            'wallet_halalah' => 0,
            'payment_halalah' => 10_000,
            'total_halalah' => 10_000,
        ];
    }
}
