<?php

namespace Database\Factories;

use App\Models\FaqEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FaqEntry> */
class FaqEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'question_ar' => fake()->realText(50).'؟',
            'question_en' => fake()->sentence().'?',
            'answer_ar' => fake()->realText(100),
            'answer_en' => fake()->paragraph(),
            'sort_order' => 10,
            'is_visible' => true,
        ];
    }
}
