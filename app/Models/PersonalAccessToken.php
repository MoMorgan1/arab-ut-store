<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Hidden(['token'])]
class PersonalAccessToken extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }
}
