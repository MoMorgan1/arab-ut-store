<?php

namespace App\Actions\Cart;

use App\Models\CartItem;
use Illuminate\Support\Facades\DB;

final readonly class PurgeRemovedCartItems
{
    private const CHUNK_SIZE = 100;

    public function __construct(private DeleteCartItemFulfillment $deleteFulfillment) {}

    /**
     * Without a cart id this is the hourly sweep over every cart; with one
     * it only clears the visitor's own expired undo window, so a page render
     * never pays for the whole store.
     */
    public function execute(?int $cartId = null): int
    {
        $purged = 0;

        do {
            $purgedChunk = DB::transaction(fn (): int => $this->purgeChunk($cartId), attempts: 3);
            $purged += $purgedChunk;
        } while ($purgedChunk === self::CHUNK_SIZE);

        return $purged;
    }

    private function purgeChunk(?int $cartId): int
    {
        $expired = CartItem::query()
            ->withRemoved()
            ->when($cartId !== null, fn ($query) => $query->where('cart_id', $cartId))
            ->whereNotNull('removed_at')
            ->where('removed_at', '<', now()->subMinutes(30))
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE)
            ->lockForUpdate()
            ->get();

        foreach ($expired as $item) {
            $this->deleteFulfillment->execute($item);
            $item->delete();
        }

        return $expired->count();
    }
}
