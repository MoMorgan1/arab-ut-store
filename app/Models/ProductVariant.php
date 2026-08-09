<?php

namespace App\Models;

use App\Enums\Market;
use App\Enums\Platform;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property Platform $platform
 * @property Market $market
 */
class ProductVariant extends DomainModel
{
    protected static function booted(): void
    {
        static::saving(function (ProductVariant $variant): void {
            $variant->market = $variant->platform->market();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'platform' => Platform::class,
            'market' => Market::class,
            'authority' => ProductAuthority::class,
            'configuration' => 'array',
            'is_active' => 'boolean',
            'price_halalah' => 'integer',
            'sale_price_halalah' => 'integer',
            'quantity_k' => 'integer',
            'price_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<CatalogSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CatalogSource::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<PriceRule, $this> */
    public function priceRules(): HasMany
    {
        return $this->hasMany(PriceRule::class);
    }

    /** @return HasMany<PriceProposal, $this> */
    public function priceProposals(): HasMany
    {
        return $this->hasMany(PriceProposal::class);
    }

    /** @return HasMany<PriceHistory, $this> */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }
}
