<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** @property ?Carbon $admin_hidden_at */
class Category extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'admin_hidden_at' => 'immutable_datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * A category is on the storefront when automation says so and no admin has
     * overridden it. `is_visible` is snapshot-owned; `admin_hidden_at` is not.
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function applyStorefrontVisible(Builder $query): void
    {
        $query->where('is_visible', true)->whereNull('admin_hidden_at');
    }

    /** The loaded-model form of applyStorefrontVisible(). */
    public function isStorefrontVisible(): bool
    {
        return (bool) $this->is_visible && $this->admin_hidden_at === null;
    }

    /** @return BelongsTo<CatalogSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CatalogSource::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
