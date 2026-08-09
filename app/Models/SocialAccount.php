<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends DomainModel
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
