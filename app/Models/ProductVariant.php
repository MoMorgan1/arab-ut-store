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
 * @property array<string, mixed>|null $configuration
 * @property int $price_halalah
 * @property int|null $sale_price_halalah
 * @property int|null $admin_price_halalah
 * @property array<string, mixed>|null $admin_completion_pricing
 * @property ServiceType $service_type
 * @property ProductAuthority $authority
 */
class ProductVariant extends DomainModel
{
    /**
     * The price a customer actually pays, before completion tiers.
     *
     * `price_halalah` and `sale_price_halalah` are rewritten by the catalog
     * snapshot on every run; `admin_price_halalah` is not, so while it is set
     * it wins. Every quote, cart and checkout path reads through here.
     */
    public function effectivePriceHalalah(): int
    {
        return $this->admin_price_halalah
            ?? $this->sale_price_halalah
            ?? (int) $this->price_halalah;
    }

    /**
     * The configuration SbcCompletionPricing should read: the admin's tier
     * table when one is set, otherwise automation's.
     *
     * @return array<string, mixed>
     */
    public function effectivePricingConfiguration(): array
    {
        if (is_array($this->admin_completion_pricing)) {
            return ['completionPricing' => $this->admin_completion_pricing];
        }

        return is_array($this->configuration) ? $this->configuration : [];
    }

    public function hasAdminPriceOverride(): bool
    {
        return $this->admin_price_halalah !== null;
    }

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
            'admin_completion_pricing' => 'array',
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
