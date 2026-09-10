<?php

namespace App\Actions\Checkout;

use App\Exceptions\Payments\PaymentConfigurationException;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentManager;

/**
 * Close an order's hosted Paylink invoice before the order is cancelled.
 *
 * Shared by the customer's own cancel and the expiry job so both take the
 * same money-safe order: the gateway invoice is closed first, and only when
 * that succeeded is anything written locally. An order that never reached
 * Paylink has nothing to close.
 */
final class CloseGatewayInvoice
{
    /**
     * The newest Paylink payment that actually reached the gateway, or null
     * when the checkout never got that far.
     */
    public function pendingInvoice(Order $order): ?Payment
    {
        $payment = $order->payments()
            ->where('provider', 'paylink')
            ->whereNotNull('provider_payment_id')
            ->where('provider_payment_id', '!=', '')
            ->latest('id')
            ->first();

        return $payment instanceof Payment ? $payment : null;
    }

    /**
     * @throws PaymentGatewayException|PaymentConfigurationException when the gateway refuses or cannot be reached
     */
    public function execute(Order $order, PaymentManager $payments): void
    {
        $payment = $this->pendingInvoice($order);

        if (! $payment instanceof Payment) {
            return;
        }

        $payments->gateway()->cancelInvoice((string) $payment->provider_payment_id);
    }
}
