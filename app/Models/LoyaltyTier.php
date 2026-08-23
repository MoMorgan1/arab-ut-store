<?php

namespace App\Models;

/**
 * @property string $key
 * @property string $name_ar
 * @property string $name_en
 * @property int $rank
 * @property int $minimum_lifetime_spend_halalah
 * @property int $cashback_basis_points
 * @property bool $is_active
 */
class LoyaltyTier extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'minimum_lifetime_spend_halalah' => 'integer',
            'cashback_basis_points' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
