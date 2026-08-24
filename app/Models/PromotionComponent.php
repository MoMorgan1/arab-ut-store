<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $promotion_id
 * @property int|null $product_id
 * @property int $quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Promotion $promotion
 * @property-read Product|null $product
 */
class PromotionComponent extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'promotion_id' => 'integer',
            'product_id' => 'integer',
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
