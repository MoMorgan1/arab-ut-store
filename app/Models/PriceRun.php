<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The two timestamps are cast, so they are Carbon instances however the column
 * spells them; without saying so here they read as plain strings.
 *
 * @property int|null $pricing_version
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 */
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
