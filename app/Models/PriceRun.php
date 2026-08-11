<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceRun extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'pricing_version' => 'integer',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<CatalogSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CatalogSource::class);
    }

    /** @return HasMany<PriceProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(PriceProposal::class);
    }

    /** @return HasMany<PriceHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }
}
