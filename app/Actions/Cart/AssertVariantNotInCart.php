<?php

namespace App\Actions\Cart;

use App\Exceptions\Cart\DuplicateCartItem;
use App\Models\Cart;
use App\Models\ProductVariant;

final readonly class AssertVariantNotInCart
{
    /**
     * Quantities and completion counts already scale the price through tiers,
     * so a second line of the same variant would bypass tier pricing.
     * Runs inside the add transaction while AcquireActiveCart holds the cart
     * row lock, so the exists-check is race-safe.
     */
    public function execute(Cart $cart, ProductVariant $variant): void
    {
        if ($cart->items()->where('product_variant_id', $variant->id)->exists()) {
            throw new DuplicateCartItem('This product variant is already in the cart.');
        }
    }
}
