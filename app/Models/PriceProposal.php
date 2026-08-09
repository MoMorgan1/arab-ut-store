<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceProposal extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'current_price_halalah' => 'integer',
            'proposed_price_halalah' => 'integer',
            'expected_version' => 'integer',
        ];
    }

    /** @return BelongsTo<PriceRun, $this> */
    public function priceRun(): BelongsTo
    {
        return $this->belongsTo(PriceRun::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
