<?php

namespace App\Models;

use App\Enums\OrderItemStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'platform' => Platform::class,
            'status' => OrderItemStatus::class,
            'quantity' => 'integer',
            'unit_price_halalah' => 'integer',
            'subtotal_halalah' => 'integer',
            'discount_halalah' => 'integer',
            'total_halalah' => 'integer',
            'configuration' => 'array',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return HasOne<OrderItemSecret, $this> */
    public function secret(): HasOne
    {
        return $this->hasOne(OrderItemSecret::class);
    }

    /** @return HasOne<FulfillmentJob, $this> */
    public function fulfillmentJob(): HasOne
    {
        return $this->hasOne(FulfillmentJob::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /** @return HasMany<NotificationDelivery, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
