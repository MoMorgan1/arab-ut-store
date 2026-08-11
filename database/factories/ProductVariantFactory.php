<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        $platform = fake()->randomElement(Platform::cases());

        return [
            'product_id' => Product::factory(),
            'source_id' => null,
            'external_id' => null,
            'sku' => 'TEST_'.fake()->unique()->numerify('########'),
            'service_type' => ServiceType::Sbc,
            'platform' => $platform,
            'market' => $platform->market(),
            'authority' => ProductAuthority::Manual,
            'name_ar' => 'خيار تجريبي',
            'name_en' => 'Test option',
            'price_halalah' => 10_000,
            'sale_price_halalah' => null,
            'configuration' => [],
            'is_active' => true,
        ];
    }
}
