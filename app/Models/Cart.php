<?php

namespace App\Models;

use App\ValueObjects\Cart\CartOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends DomainModel
{
    /** @param Builder<Cart> $query */
    public function scopeActiveForOwner(Builder $query, CartOwner $owner): void
    {
        $query->where('status', 'active')
            ->where('currency', 'SAR')
            ->where('active_owner_key', $owner->databaseKey());

        if ($owner->userId() !== null) {
            $query->where('user_id', $owner->userId());

            return;
        }

        $query->whereNull('user_id')
            ->where('session_key', $owner->sessionKey());
    }

    /** @param Builder<Cart> $query */
    public function scopeActiveForUser(Builder $query, int $userId): void
    {
        $this->scopeActiveForOwner($query, CartOwner::user($userId));
    }

    public static function activeOwnerKey(int $userId): string
    {
        return CartOwner::user($userId)->databaseKey();
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
