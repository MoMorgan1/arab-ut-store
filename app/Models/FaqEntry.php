<?php

namespace App\Models;

class FaqEntry extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
