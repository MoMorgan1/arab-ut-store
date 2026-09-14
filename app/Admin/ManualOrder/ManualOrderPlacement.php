<?php

namespace App\Admin\ManualOrder;

use App\Enums\DeliveryPhase;
use App\Enums\Supplier;

/**
 * An item staff already lodged with a supplier themselves.
 *
 * The store records the reference and nothing else: the placement happened
 * outside it, so there is no request to retry and no payload to compose. What
 * this buys is tracking, which can only begin once a reference exists.
 *
 * A challenge placement also carries the challenge ids, and not optionally:
 * `RecordSupplierPlacement` refuses one without them, because a challenge job
 * with no ids is untrackable the moment it lands. The coins phase takes none.
 */
final readonly class ManualOrderPlacement
{
    /** @param  list<string>  $challengeIds  required on the challenge phase, empty on coins */
    public function __construct(
        public Supplier $supplier,
        public string $supplierOrderId,
        public DeliveryPhase $phase,
        public array $challengeIds = [],
    ) {}
}
