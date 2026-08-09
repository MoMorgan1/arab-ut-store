<?php

namespace App\Models;

class LoyaltyTier extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'minimum_lifetime_spend_halalah' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
