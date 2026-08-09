<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneVerification extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
