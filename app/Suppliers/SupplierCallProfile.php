<?php

namespace App\Suppliers;

/**
 * How long one call to a supplier is allowed to take.
 *
 * The polling profile is deliberately impatient: a hung supplier must not eat
 * a sweep tick, and a skipped read costs nothing because the next one is
 * seconds away. The action profile is the house convention for a call that
 * has to actually land while somebody is waiting for it - 5s connect, 12s
 * total, the same numbers used by App\Actions\Fulfillment\PublishOrderPaidEvent.
 */
enum SupplierCallProfile: string
{
    case Polling = 'polling';
    case Action = 'action';

    public function connectTimeoutSeconds(): int
    {
        return match ($this) {
            self::Polling => 3,
            self::Action => 5,
        };
    }

    public function timeoutSeconds(): int
    {
        return match ($this) {
            self::Polling => 5,
            self::Action => 12,
        };
    }
}
