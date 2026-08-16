<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $bytes
 * @property string $sha256
 */
#[Hidden(['path'])]
class FulfillmentAttachment extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<CartItem, $this> */
    public function cartItem(): BelongsTo
    {
        return $this->belongsTo(CartItem::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
