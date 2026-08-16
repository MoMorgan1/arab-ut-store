<?php

namespace App\Models;

use App\Enums\ServiceType;

/**
 * @property ServiceType $service_type
 * @property int $version
 * @property array<string, mixed> $configuration
 * @property bool $is_active
 */
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
