<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $quantity
 * @property int $unit_price_halalah
 * @property int $total_halalah
 * @property array<string, mixed>|null $configuration
 */
class CartItem extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'unit_price_halalah' => 'integer',
            'total_halalah' => 'integer',
            'removed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('notRemoved', function (Builder $query): void {
            $query->whereNull($query->getModel()->getTable().'.removed_at');
        });
    }

    /** @param Builder<CartItem> $query */
    public function scopeWithRemoved(Builder $query): void
    {
        $query->withoutGlobalScope('notRemoved');
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return HasOne<CartItemSecret, $this> */
    public function secret(): HasOne
    {
        return $this->hasOne(CartItemSecret::class);
    }

    /** @return HasOne<FulfillmentAttachment, $this> */
    public function squadImage(): HasOne
    {
        return $this->hasOne(FulfillmentAttachment::class)->where('kind', 'squad_image');
    }
}
