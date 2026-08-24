<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name_ar
 * @property string $name_en
 * @property string|null $badge_ar
 * @property string|null $badge_en
 * @property string $scope
 * @property int|null $category_id
 * @property int|null $product_id
 * @property string|null $service_type
 * @property string $discount_type
 * @property int $value
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property-read Category|null $category
 * @property-read Product|null $product
 */
class Promotion extends DomainModel
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_SERVICE = 'service';

    public const SCOPE_PRODUCT = 'product';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'product_id' => 'integer',
            'value' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /** @param  Builder<Promotion>  $query */
    public function scopeActive(Builder $query): void
    {
        $now = Carbon::now();

        $query->where('is_active', true)
            ->where(fn (Builder $inner) => $inner->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $inner) => $inner->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
