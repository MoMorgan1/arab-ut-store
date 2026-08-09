<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItemSecret extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'encrypted_payload' => 'encrypted:array',
            'masked_summary' => 'array',
            'retained_until' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return HasMany<SecretAccessLog, $this> */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(SecretAccessLog::class);
    }
}
