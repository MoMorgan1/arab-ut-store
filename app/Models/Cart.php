<?php

namespace App\Models;

use App\ValueObjects\Cart\CartOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property bool $use_wallet
 * @property int|null $coupon_id
 */
class Cart extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'use_wallet' => 'boolean',
        ];
    }

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

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
