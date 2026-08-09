<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

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
