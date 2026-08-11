<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponRedemption extends DomainModel
{
    public const CREATED_AT = 'redeemed_at';

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'redeemed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
