<?php

namespace Database\Factories;

use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $slug = fake()->unique()->slug();
        $name = str($slug)->replace('-', ' ');

        return [
            'category_id' => Category::factory(),
            'source_id' => null,
            'external_id' => null,
            'slug' => $slug,
            'service_type' => ServiceType::Sbc,
            'authority' => ProductAuthority::Manual,
            'name_ar' => 'خدمة تجريبية',
            'name_en' => $name->title(),
            'description_ar' => 'وصف تجريبي غير حساس',
            'description_en' => 'Non-sensitive test service.',
            'is_visible' => true,
        ];
    }
}
