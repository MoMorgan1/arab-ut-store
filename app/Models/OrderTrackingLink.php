<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $token_hash
 * @property string $token_encrypted
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 */
#[Hidden(['token_encrypted'])]
class OrderTrackingLink extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token_encrypted' => 'encrypted',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
