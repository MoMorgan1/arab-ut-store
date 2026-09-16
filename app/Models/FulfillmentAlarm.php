<?php

namespace App\Models;

use App\Enums\FulfillmentAlarmKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property FulfillmentAlarmKind $kind
 * @property array<string, mixed>|null $context
 */
class FulfillmentAlarm extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => FulfillmentAlarmKind::class,
            'context' => 'array',
            'raised_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
