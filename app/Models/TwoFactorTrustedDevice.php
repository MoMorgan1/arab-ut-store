<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $token_hash
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property ?Carbon $last_used_at
 * @property Carbon $expires_at
 */
class TwoFactorTrustedDevice extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
