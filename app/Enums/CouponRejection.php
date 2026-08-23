<?php

namespace App\Enums;

enum CouponRejection: string
{
    case Invalid = 'coupon_invalid';

    case Expired = 'coupon_expired';

    case Limit = 'coupon_limit';

    case Minimum = 'coupon_minimum';

    case FirstOrderOnly = 'coupon_first_order_only';
}
