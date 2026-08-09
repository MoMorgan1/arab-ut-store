<?php

namespace Database\Factories;

use App\Enums\OrderItemStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderItem> */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'sku' => 'SNAPSHOT_'.fake()->unique()->numerify('########'),
            'name_ar' => 'عنصر طلب تجريبي',
            'name_en' => 'Test order item',
            'service_type' => ServiceType::Sbc,
            'platform' => Platform::PlayStation,
            'status' => OrderItemStatus::PendingPayment,
            'quantity' => 1,
            'unit_price_halalah' => 10_000,
            'subtotal_halalah' => 10_000,
            'discount_halalah' => 0,
            'total_halalah' => 10_000,
            'configuration' => [],
        ];
    }
}
