<?php

namespace App\Actions\Fulfillment;

use App\Models\FulfillmentJob;
use Carbon\CarbonImmutable;

/**
 * What a fulfillment job said before an observation was written over it.
 *
 * Taken on the line before the overwrite, because the overwrite is what
 * destroys it: how long this job went between readings, and whether the newer
 * one says anything the older one did not, are both computable exactly once
 * and only from here.
 */
final readonly class ObservationSnapshot
{
    public function __construct(
        public ?CarbonImmutable $observedAt,
        public ?string $observedState,
        public ?int $coinsDelivered,
        public ?int $squadsDone,
        public ?int $solvesDone,
    ) {}

    public static function of(FulfillmentJob $job): self
    {
        return new self(
            $job->observed_at,
            $job->observed_state,
            $job->coins_delivered,
            $job->squads_done,
            $job->solves_done,
        );
    }
}
