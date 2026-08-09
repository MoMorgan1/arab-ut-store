<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends DomainModel
{
    protected $table = 'price_history';

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_halalah' => 'integer',
            'sale_price_halalah' => 'integer',
            'effective_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<PriceRun, $this> */
    public function priceRun(): BelongsTo
    {
        return $this->belongsTo(PriceRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
