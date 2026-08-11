<?php

namespace App\Models;

class ExchangeRate extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
