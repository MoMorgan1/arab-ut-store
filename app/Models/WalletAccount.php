<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $balance_halalah
 * @property array<string, mixed>|null $metadata
 */
class WalletAccount extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'balance_halalah' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WalletEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }
}
