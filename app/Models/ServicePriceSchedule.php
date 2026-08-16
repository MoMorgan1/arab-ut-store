<?php

namespace App\Models;

use App\Enums\ServiceType;

class ServicePriceSchedule extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'version' => 'integer',
            'configuration' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
