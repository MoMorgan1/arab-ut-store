<?php

namespace App\Actions\Cart;

use App\Models\CartItem;
use App\Models\FulfillmentAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteCartItemFulfillment
{
    public function execute(CartItem $cartItem): void
    {
        $attachment = $cartItem->squadImage()->first();

        if ($attachment instanceof FulfillmentAttachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }

        DB::transaction(function () use ($cartItem, $attachment): void {
            $cartItem->secret()->delete();
            $attachment?->delete();
        });
    }
}
