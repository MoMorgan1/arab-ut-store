<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $coupon_id
 * @property string $target_type
 * @property int $target_id
 * @property-read Coupon $coupon
 */
class CouponTarget extends DomainModel
{
    public const TYPE_CATEGORY = 'category';

    public const TYPE_PRODUCT = 'product';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'coupon_id' => 'integer',
            'target_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
