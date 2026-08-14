<?php

namespace App\Payments;

use InvalidArgumentException;

final readonly class PaymentInvoice
{
    public function __construct(
        public string $transactionNo,
        public string $orderNumber,
        public int $amountHalalah,
        public string $currency,
        public string $status,
        public ?string $paymentUrl,
        public ?string $paymentMethod,
    ) {
        if (preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $this->transactionNo) !== 1
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $this->orderNumber) !== 1
            || $this->amountHalalah < 0
            || $this->currency !== 'SAR'
            || ! in_array($this->status, ['pending', 'paid', 'cancelled'], true)) {
            throw new InvalidArgumentException('The payment invoice is invalid.');
        }

        if ($this->paymentUrl !== null && ! self::isPaylinkPaymentUrl($this->paymentUrl)) {
            throw new InvalidArgumentException('The payment URL is invalid.');
        }

        if ($this->status === 'pending' && $this->paymentUrl === null) {
            throw new InvalidArgumentException('A pending payment requires a payment URL.');
        }
    }

    private static function isPaylinkPaymentUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'payment.paylink.sa';
    }
}
