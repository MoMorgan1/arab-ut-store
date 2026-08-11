<?php

namespace App\Models;

use App\Enums\FulfillmentStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FulfillmentJob extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => FulfillmentStatus::class,
            'attempt_count' => 'integer',
            'actual_cost_halalah' => 'integer',
            'next_poll_at' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return HasMany<FulfillmentAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(FulfillmentAttempt::class);
    }
}
