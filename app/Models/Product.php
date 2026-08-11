<?php

namespace App\Models;

use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property ServiceType $service_type */
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
        ];
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
