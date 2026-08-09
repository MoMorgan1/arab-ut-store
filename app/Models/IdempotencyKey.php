<?php

namespace App\Models;

class IdempotencyKey extends DomainModel
{
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
