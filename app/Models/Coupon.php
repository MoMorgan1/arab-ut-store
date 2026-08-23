<?php

namespace App\Models;

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
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 */
class Coupon extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'minimum_order_halalah' => 'integer',
            'maximum_discount_halalah' => 'integer',
            'usage_limit' => 'integer',
            'per_user_limit' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
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
