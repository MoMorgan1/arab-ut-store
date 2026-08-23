<?php

namespace App\Exceptions\Checkout;

use Exception;
use Throwable;

/**
 * Raised when a cart's attached coupon became invalid between apply and
 * checkout. Carries the cart id so the caller can detach the coupon after
 * the checkout transaction has rolled back.
 */
final class StaleCartCoupon extends Exception
{
    public function __construct(
        public readonly int $cartId,
        public readonly string $failure,
        ?Throwable $previous = null,
    ) {
        parent::__construct($failure, 0, $previous);
    }
}
