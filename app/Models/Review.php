<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_visible' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
