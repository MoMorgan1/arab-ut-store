<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $slug = fake()->unique()->slug();
        $name = str($slug)->replace('-', ' ');

        return [
            'slug' => $slug,
            'name_ar' => 'فئة تجريبية',
            'name_en' => $name->title(),
            'description_ar' => 'وصف تجريبي غير حساس',
            'description_en' => 'Non-sensitive test category.',
            'is_visible' => true,
        ];
    }
}
