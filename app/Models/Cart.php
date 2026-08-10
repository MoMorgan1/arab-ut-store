<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends DomainModel
{
    /** @param Builder<Cart> $query */
    public function scopeActiveForUser(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId)
            ->where('status', 'active')
            ->where('currency', 'SAR')
            ->where('active_owner_key', self::activeOwnerKey($userId));
    }

    public static function activeOwnerKey(int $userId): string
    {
        return "user:{$userId}";
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
