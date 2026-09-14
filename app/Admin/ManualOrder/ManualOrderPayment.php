<?php

namespace App\Admin\ManualOrder;

use Carbon\CarbonImmutable;

/**
 * Money that already arrived, outside the store.
 *
 * The reference is the bank's, not ours, and it is the only way to tie the row
 * to a statement line later - which is the whole reason a bank transfer is
 * recorded as a payment rather than left implicit.
 */
final readonly class ManualOrderPayment
{
    public function __construct(
        public int $amountHalalah,
        public string $reference,
        public CarbonImmutable $receivedAt,
    ) {}
}
