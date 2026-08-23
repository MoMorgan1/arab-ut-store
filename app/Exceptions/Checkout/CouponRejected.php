<?php

namespace App\Exceptions\Checkout;

use App\Enums\CouponRejection;
use DomainException;

final class CouponRejected extends DomainException
{
    public function __construct(
        public readonly CouponRejection $reason,
        public readonly int $minimumOrderHalalah = 0,
    ) {
        parent::__construct($reason->value);
    }
}
