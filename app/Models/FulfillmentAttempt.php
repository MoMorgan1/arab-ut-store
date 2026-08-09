<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FulfillmentAttempt extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'request_metadata' => 'array',
            'response_metadata' => 'array',
            'attempt_number' => 'integer',
            'actual_cost_halalah' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<FulfillmentJob, $this> */
    public function fulfillmentJob(): BelongsTo
    {
        return $this->belongsTo(FulfillmentJob::class);
    }
}
