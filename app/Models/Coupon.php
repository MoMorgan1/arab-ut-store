<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string|null $description_ar
 * @property string|null $description_en
 * @property string $discount_type
 * @property int $value
 * @property int $minimum_order_halalah
 * @property int|null $maximum_discount_halalah
 * @property int|null $usage_limit
 * @property int|null $per_user_limit
 * @property string $scope
 * @property string|null $service_type
 * @property bool $first_order_only
 * @property bool $excludes_promoted_items
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property-read Collection<int, CouponTarget> $targets
 * @property-read Collection<int, CouponRedemption> $redemptions
 * @property-read Collection<int, OrderDiscount> $orderDiscounts
 */
class Coupon extends DomainModel
{
    public const SCOPE_ORDER = 'order';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_PRODUCT = 'product';

    public const SCOPE_SERVICE = 'service';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'minimum_order_halalah' => 'integer',
            'maximum_discount_halalah' => 'integer',
            'usage_limit' => 'integer',
            'per_user_limit' => 'integer',
            'first_order_only' => 'boolean',
            'excludes_promoted_items' => 'boolean',
            'is_active' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<CouponTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(CouponTarget::class);
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /** @return HasMany<OrderDiscount, $this> */
    public function orderDiscounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }
}
