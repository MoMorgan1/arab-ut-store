<?php

namespace App\Models;

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
}
