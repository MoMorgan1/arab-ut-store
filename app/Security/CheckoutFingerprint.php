<?php

namespace App\Security;

use App\Exceptions\Checkout\CheckoutUnavailable;
use App\Models\Cart;
use App\Models\CartItem;
use JsonException;

final class CheckoutFingerprint
{
    public static function generate(Cart $cart, string $locale, string $key): string
    {
        $items = $cart->items
            ->sortBy('id')
            ->map(static fn (CartItem $item): array => [
                'public_id' => $item->public_id,
                'variant_id' => $item->product_variant_id,
                'quantity' => $item->quantity,
                'unit_price_halalah' => $item->unit_price_halalah,
                'total_halalah' => $item->total_halalah,
                'configuration' => $item->configuration,
                'updated_at' => $item->updated_at?->toISOString(),
                'secret_public_id' => $item->secret?->public_id,
                'secret_updated_at' => $item->secret?->updated_at?->toISOString(),
                'attachment_public_id' => $item->squadImage?->public_id,
                'attachment_sha256' => $item->squadImage?->sha256,
                'attachment_updated_at' => $item->squadImage?->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        try {
            $canonical = json_encode([
                'cart' => $cart->public_id,
                'user' => $cart->user_id,
                'locale' => $locale,
                'items' => $items,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new CheckoutUnavailable('The cart cannot be checked out.');
        }

        return hash_hmac('sha256', $canonical, $key);
    }
}
