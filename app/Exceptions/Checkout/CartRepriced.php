<?php

namespace App\Exceptions\Checkout;

use Exception;
use Throwable;

/**
 * Raised when the total we are about to charge is not the total the customer
 * was shown. Carries both figures so the storefront can ask for a confirmation
 * instead of showing an opaque refusal; the "previous" pair is what the client
 * sent, because nothing on the server records what was rendered.
 *
 * Also carries the cart id: when a coupon fell off, the caller detaches it
 * after the checkout transaction has rolled back.
 */
final class CartRepriced extends Exception
{
    public function __construct(
        public readonly int $cartId,
        public readonly int $orderTotalHalalah,
        public readonly int $previousOrderTotalHalalah,
        public readonly int $payableHalalah,
        public readonly int $previousPayableHalalah,
        public readonly bool $couponRemoved = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct('The cart total changed.', 0, $previous);
    }
}
