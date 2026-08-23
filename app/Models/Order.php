<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $subtotal_halalah
 * @property int $discount_halalah
 * @property int $wallet_halalah
 * @property int $payment_halalah
 * @property int $total_halalah
 * @property string $currency
 * @property string $locale
 * @property string $order_number
 * @property OrderStatus $status
 */
class Order extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_halalah' => 'integer',
            'discount_halalah' => 'integer',
            'wallet_halalah' => 'integer',
            'payment_halalah' => 'integer',
            'total_halalah' => 'integer',
            'placed_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderDiscount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** @return HasMany<WalletEntry, $this> */
    public function walletEntries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /** @return HasOne<Receipt, $this> */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    /** @return HasMany<NotificationDelivery, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
