<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecretAccessLog extends DomainModel
{
    public const CREATED_AT = 'accessed_at';

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'accessed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<OrderItemSecret, $this> */
    public function secret(): BelongsTo
    {
        return $this->belongsTo(OrderItemSecret::class, 'order_item_secret_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
