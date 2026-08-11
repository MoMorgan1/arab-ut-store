<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationEvent extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'schema_version' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<NotificationDelivery, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
