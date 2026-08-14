<?php

namespace App\Checkout;

use App\Models\Order;
use App\Models\Payment;

final readonly class CheckoutResult
{
    public function __construct(
        public Order $order,
        public Payment $payment,
        public bool $replayed,
    ) {}
}
