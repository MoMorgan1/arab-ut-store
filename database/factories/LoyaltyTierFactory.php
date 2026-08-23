<?php

namespace Database\Factories;

use App\Models\LoyaltyTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoyaltyTier> */
class LoyaltyTierFactory extends Factory
{
    protected $model = LoyaltyTier::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        static $rank = 1;

        return [
            'key' => 'tier_'.$this->faker->unique()->slug(1),
            'name_ar' => 'مستوى '.$rank,
            'name_en' => 'Tier '.$rank,
            'rank' => $rank++,
            'minimum_lifetime_spend_halalah' => 0,
            'cashback_basis_points' => 200,
            'is_active' => true,
        ];
    }
}
