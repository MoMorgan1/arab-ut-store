<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogSyncRun extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_complete_snapshot' => 'boolean',
            'source_count' => 'integer',
            'applied_count' => 'integer',
            'held_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CatalogSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CatalogSource::class);
    }

    /** @return HasMany<CatalogSyncItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CatalogSyncItem::class);
    }
}
