<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_halalah' => 'integer',
            'issued_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
