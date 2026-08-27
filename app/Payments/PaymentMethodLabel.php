<?php

namespace App\Payments;

use App\Models\Payment;

/**
 * What the customer paid with, as they would describe it themselves.
 *
 * A receipt names the method - mada, a card, the wallet - not the gateway that
 * moved the money. Naming Paylink told the customer nothing and read like
 * plumbing left on show, so the provider is resolved down to the method the
 * gateway reported, and anything unrecognised falls back to "card" rather than
 * leaking an internal string.
 */
final class PaymentMethodLabel
{
    /** Returned keys are translation keys under `payments.method_*` in both languages. */
    public static function for(?Payment $payment): ?string
    {
        if (! $payment instanceof Payment) {
            return null;
        }

        if ((string) $payment->getAttribute('provider') === 'wallet') {
            return 'wallet';
        }

        $metadata = $payment->getAttribute('provider_metadata');
        $method = is_array($metadata) ? ($metadata['payment_method'] ?? null) : null;

        if (! is_string($method)) {
            return 'card';
        }

        return match (strtolower(trim($method))) {
            'mada' => 'mada',
            'visa' => 'visa',
            'mastercard', 'master' => 'mastercard',
            'applepay', 'apple_pay', 'apple pay' => 'apple_pay',
            'stcpay', 'stc_pay', 'stc pay' => 'stc_pay',
            default => 'card',
        };
    }
}
