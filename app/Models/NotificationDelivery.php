<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => NotificationStatus::class,
            'payload' => 'array',
            'available_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<IntegrationEvent, $this> */
    public function integrationEvent(): BelongsTo
    {
        return $this->belongsTo(IntegrationEvent::class);
    }
}
