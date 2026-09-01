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

    /**
     * Keep the denormalised sbc_category column in step with the configuration
     * a test hands in. The catalog filter reads the column and no longer falls
     * back to the JSON, so a variant built with an sbcCategory in its
     * configuration but a null column is invisible to the SBC page -- a test
     * written that way would fail for a reason that has nothing to do with what
     * it is testing.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (ProductVariant $variant): void {
            $configuration = $variant->configuration;

            if ($variant->sbc_category === null && is_array($configuration)
                && is_string($configuration['sbcCategory'] ?? null)) {
                $variant->sbc_category = $configuration['sbcCategory'];
            }
        });
    }
}
