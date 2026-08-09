<?php

namespace App\Models;

use App\Enums\ProductAuthority;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogSource extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'authority' => ProductAuthority::class,
            'is_enabled' => 'boolean',
        ];
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'source_id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'source_id');
    }

    /** @return HasMany<CatalogSyncRun, $this> */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(CatalogSyncRun::class, 'source_id');
    }

    /** @return HasMany<PriceRun, $this> */
    public function priceRuns(): HasMany
    {
        return $this->hasMany(PriceRun::class, 'source_id');
    }
}
