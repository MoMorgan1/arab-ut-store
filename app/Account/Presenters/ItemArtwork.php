<?php

namespace App\Account\Presenters;

use App\Enums\ServiceType;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Support\Facades\Storage;

/**
 * The picture that stands for an order item.
 *
 * It lives on its own because two screens now show the same item: the invoice
 * row on the order page and the tracking page it links to. Two copies of this
 * rule would eventually disagree about which image a Challenge shows, and the
 * customer would notice before we did.
 */
final class ItemArtwork
{
    public static function for(OrderItem $item): string
    {
        if ($item->service_type === ServiceType::Coins) {
            return '/images/store/coins/ut-coin-80.webp';
        }

        $product = $item->productVariant?->product;
        $media = $product instanceof Product
            ? self::safeUrl($product->media->first())
            : null;

        return $media ?? ServiceArtwork::for($item->service_type);
    }

    /**
     * A media row only earns a URL if it is on the public disk and its path is
     * a plain relative path: the value is stored, and a stored value is not a
     * safe one.
     */
    private static function safeUrl(?ProductMedia $media): ?string
    {
        if (! $media instanceof ProductMedia || $media->disk !== 'public') {
            return null;
        }

        $path = (string) $media->path;

        if ($path === ''
            || str_contains($path, '..')
            || preg_match('/\A[A-Za-z0-9_\/.\-]+\z/D', $path) !== 1) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
