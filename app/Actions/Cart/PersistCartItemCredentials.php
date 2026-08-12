<?php

namespace App\Actions\Cart;

use App\Models\CartItem;
use App\Models\CartItemSecret;

final readonly class PersistCartItemCredentials
{
    /** @param array<string, mixed> $credentials */
    public function execute(CartItem $cartItem, array $credentials): void
    {
        $secret = new CartItemSecret([
            'cart_item_id' => $cartItem->id,
            'masked_summary' => [
                'has_password' => true,
                'backup_code_count' => count($credentials['backup_codes']),
            ],
            'retained_until' => null,
        ]);
        $secret->encrypted_payload = $credentials;
        $secret->save();
    }
}
