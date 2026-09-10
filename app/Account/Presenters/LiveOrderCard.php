<?php

namespace App\Account\Presenters;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

final class LiveOrderCard
{
    /**
     * @return array{
     *     id: string,
     *     source: 'live',
     *     number: string,
     *     status: string,
     *     placedAt: string,
     *     summary: string,
     *     itemCount: int,
     *     images: list<string>,
     *     total: array{amountMinor: string, currency: string},
     *     walletPayment: array{amountMinor: string, currency: string}|null,
     *     detailUrl: string
     * }
     */
    public function for(Order $order, string $locale): array
    {
        $items = $order->items;
        $firstItem = $items->first();
        $itemCount = $items->count();
        $placedAt = $order->getAttribute('placed_at') ?? $order->getAttribute('created_at');
        $walletHalalah = (int) ($order->getAttribute('wallet_halalah') ?? 0);

        return [
            'id' => (string) $order->getAttribute('public_id'),
            'source' => 'live',
            'number' => (string) $order->getAttribute('order_number'),
            'status' => $order->status->forCustomer()->value,
            'placedAt' => $placedAt instanceof CarbonInterface ? $placedAt->toIso8601String() : '',
            'summary' => $this->summary($firstItem, $itemCount, $locale),
            'itemCount' => $itemCount,
            'images' => $this->images($items),
            'total' => AccountMoney::fromMinor(
                (int) $order->getAttribute('total_halalah'),
                (string) $order->getAttribute('currency'),
            ),
            'walletPayment' => $walletHalalah > 0
                ? AccountMoney::fromMinor(
                    $walletHalalah,
                    (string) $order->getAttribute('currency'),
                )
                : null,
            'detailUrl' => route(
                $locale === 'en' ? 'localized.account.orders.show' : 'account.orders.show',
                ['order' => $order->getAttribute('public_id')],
                absolute: false,
            ),
        ];
    }

    /**
     * Up to two distinct service artworks, in item order, for the card's
     * thumbnail stack. A third distinct service is folded into the count.
     *
     * @param  Collection<int, OrderItem>  $items
     * @return list<string>
     */
    private function images(Collection $items): array
    {
        $images = [];

        foreach ($items as $item) {
            $artwork = ServiceArtwork::for($item->service_type);

            if (! in_array($artwork, $images, true)) {
                $images[] = $artwork;
            }

            if (count($images) === 2) {
                break;
            }
        }

        return $images;
    }

    private function summary(?OrderItem $firstItem, int $itemCount, string $locale): string
    {
        if (! $firstItem instanceof OrderItem) {
            return '';
        }

        $name = (string) $firstItem->getAttribute($locale === 'en' ? 'name_en' : 'name_ar');

        return $itemCount > 1 ? "{$name} +".($itemCount - 1) : $name;
    }
}
