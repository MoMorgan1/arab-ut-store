<?php

namespace App\Exceptions\Checkout;

use RuntimeException;

/**
 * Raised inside the payment-start transaction when the order stopped being
 * pending while the gateway invoice was being created. Internal to
 * StartPaylinkPayment, which turns it into a CheckoutUnavailable after
 * closing the invoice it just raised.
 */
final class OrderNoLongerPending extends RuntimeException {}
