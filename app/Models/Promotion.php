<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * @property ?string $mechanic Null means the item mechanic - the column default, and the
 *                             value an in-memory model carries before it is read back.
 * @property int|null $buy_quantity
 * @property int|null $get_quantity
 * @property int|null $max_applications
 * @property string|null $discount_target
 * @property string|null $qualifying_scope
 * @property int|null $bundle_price_halalah
 * @property bool $applies_to_promoted_items
 * @property-read Category|null $category
 * @property-read Product|null $product
 * @property-read Collection<int, PromotionComponent> $components
 * @property-read Collection<int, OrderItem> $orderItems
 */
class Promotion extends DomainModel
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_SERVICE = 'service';

    public const SCOPE_PRODUCT = 'product';

    public const MECHANIC_ITEM = 'item';

    public const MECHANIC_NTH_ITEM = 'nth_item';

    public const MECHANIC_BUNDLE = 'bundle';

    public const TARGET_CHEAPEST = 'cheapest';

    public const TARGET_MOST_EXPENSIVE = 'most_expensive';

    public const QUALIFYING_SCOPE_SAME_PRODUCT = 'same_product';

    public const QUALIFYING_SCOPE_SAME_CATEGORY = 'same_category';

    public const QUALIFYING_SCOPE_SAME_SERVICE = 'same_service';

    public const QUALIFYING_SCOPE_ANY = 'any';

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
            'buy_quantity' => 'integer',
            'get_quantity' => 'integer',
            'max_applications' => 'integer',
            'bundle_price_halalah' => 'integer',
            'applies_to_promoted_items' => 'boolean',
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

    /** @return HasMany<PromotionComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(PromotionComponent::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
