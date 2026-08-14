<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;

final readonly class PaymentManager
{
    public function gateway(): PaymentGateway
    {
        return app(PaylinkPaymentGateway::class);
    }
}
