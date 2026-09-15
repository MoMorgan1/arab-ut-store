<?php

namespace App\Actions\Cart;

use App\Exceptions\Cart\DuplicateCartItem;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;

final readonly class AssertVariantNotInCart
{
    /**
     * Quantities and completion counts already scale the price through tiers,
     * so a second line of the same variant would bypass tier pricing.
     *
     * The read has to lock. AcquireActiveCart holds the cart row for the rest
     * of the add, so a second addition waits here — but waiting is not the same
     * as seeing. Under REPEATABLE READ a plain `exists()` answers from the
     * snapshot this transaction took at its first read, which was before the
     * winner committed, so the loser looked at a cart that still had no line
     * and wrote a second one. A locking read answers from the latest committed
     * row instead, which is the whole point of asking.
     */
    public function execute(Cart $cart, ProductVariant $variant): void
    {
        $existing = $cart->items()
            ->where('product_variant_id', $variant->id)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof CartItem) {
            throw new DuplicateCartItem('This product variant is already in the cart.');
        }
    }
}
