<?php

namespace App\Actions\Fulfillment;

use App\Enums\ObservationResult;

/**
 * The result of one supplier read, without the customer-facing payload.
 *
 * The sweep and the customer refresh share {@see ObserveFulfillmentJob}, but
 * they do not share this answer: the refresh turns an outcome into an
 * ItemTracking payload, while the sweep turns it into a poll schedule. The
 * outcome carries only what both need, so neither path learns details the
 * other must not see.
 */
final readonly class ObservationOutcome
{
    public function __construct(
        public ObservationResult $result,
        public ?string $reason,
        public float $latencyMs,
    ) {}
}
