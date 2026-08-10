<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;

#[Hidden(['request_hash', 'response_body'])]
class IdempotencyKey extends DomainModel
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'request_hash', 'response_body'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
