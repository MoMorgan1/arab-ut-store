<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $encrypted_payload
 * @property array<string, mixed>|null $masked_summary
 */
#[Hidden(['encrypted_payload'])]
class CartItemSecret extends DomainModel
{
    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'encrypted_payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'encrypted_payload' => 'encrypted:array',
            'masked_summary' => 'array',
            'retained_until' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CartItem, $this> */
    public function cartItem(): BelongsTo
    {
        return $this->belongsTo(CartItem::class);
    }
}
