<?php

namespace Database\Factories;

use App\Enums\ProductAuthority;
use App\Models\CatalogSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogSource> */
class CatalogSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            'authority' => ProductAuthority::Automation,
            'is_enabled' => true,
        ];
    }
}
