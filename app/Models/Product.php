<?php

namespace App\Models;

use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ServiceType $service_type
 * @property ProductAuthority $authority
 */
class Product extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'authority' => ProductAuthority::class,
            'is_visible' => 'boolean',
            'archived_at' => 'immutable_datetime',
            'admin_hidden_at' => 'immutable_datetime',
        ];
    }

    /**
     * The single definition of "a customer may see and buy this".
     *
     * `is_visible` and `archived_at` belong to the catalog snapshot;
     * `admin_hidden_at` is the admin override the snapshot never writes. The
     * predicate lives here and nowhere else on purpose - the storefront reader,
     * the cart adders and checkout all route through this, so a product hidden
     * in the dashboard cannot still be bought through a direct link.
     *
     * Takes a plain Builder rather than Builder<Product> so it can also be
     * applied inside a whereHas() closure, where the relation builder is only
     * known as Builder<Model>.
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function applyStorefrontVisible(Builder $query): void
    {
        $query->where('is_visible', true)
            ->whereNull('archived_at')
            ->whereNull('admin_hidden_at')
            ->where(function (Builder $scoped): void {
                $scoped->whereNull('category_id')
                    ->orWhereHas('category', fn (Builder $category) => Category::applyStorefrontVisible($category));
            });
    }

    /** @param Builder<Product> $query */
    public function scopeStorefrontVisible(Builder $query): void
    {
        self::applyStorefrontVisible($query);
    }

    /** The loaded-model form of applyStorefrontVisible(); checkout uses this. */
    public function isStorefrontVisible(): bool
    {
        if (! $this->is_visible || $this->archived_at !== null || $this->admin_hidden_at !== null) {
            return false;
        }

        return $this->category === null || $this->category->isStorefrontVisible();
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<CatalogSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CatalogSource::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** @return HasMany<ProductMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class);
    }

    /** @return HasMany<CatalogSyncItem, $this> */
    public function syncItems(): HasMany
    {
        return $this->hasMany(CatalogSyncItem::class);
    }
}
