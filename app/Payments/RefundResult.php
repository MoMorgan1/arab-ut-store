<?php

namespace App\Payments;

use InvalidArgumentException;

final readonly class RefundResult
{
    public function __construct(
        public string $providerRefundId,
        public string $orderNumber,
        public int $amountHalalah,
        public string $currency,
        public string $reason,
        public int $createdAtMilliseconds,
    ) {
        if ($this->providerRefundId === ''
            || $this->orderNumber === ''
            || $this->amountHalalah < 1
            || $this->currency !== 'SAR'
            || trim($this->reason) === ''
            || $this->createdAtMilliseconds < 1) {
            throw new InvalidArgumentException('The refund result is invalid.');
        }
    }
}
