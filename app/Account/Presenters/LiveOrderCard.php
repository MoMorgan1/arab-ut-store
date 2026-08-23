<?php

namespace App\Account\Presenters;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonInterface;

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
            'status' => $order->status->value,
            'placedAt' => $placedAt instanceof CarbonInterface ? $placedAt->toIso8601String() : '',
            'summary' => $this->summary($firstItem, $itemCount, $locale),
            'itemCount' => $itemCount,
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

    private function summary(?OrderItem $firstItem, int $itemCount, string $locale): string
    {
        if (! $firstItem instanceof OrderItem) {
            return '';
        }

        $name = (string) $firstItem->getAttribute($locale === 'en' ? 'name_en' : 'name_ar');

        return $itemCount > 1 ? "{$name} +".($itemCount - 1) : $name;
    }
}
