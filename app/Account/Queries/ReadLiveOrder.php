<?php

namespace App\Account\Queries;

use App\Account\Presenters\AccountMoney;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonInterface;

final class ReadLiveOrder
{
    /** @return array<string, mixed> */
    public function for(User $user, string $publicId, string $locale): array
    {
        $order = Order::query()
            ->select([
                'id',
                'public_id',
                'user_id',
                'order_number',
                'status',
                'currency',
                'total_halalah',
                'placed_at',
                'created_at',
            ])
            ->where('public_id', $publicId)
            ->where('user_id', $user->id)
            ->with(['items' => fn ($items) => $items
                ->select([
                    'id',
                    'public_id',
                    'order_id',
                    'name_ar',
                    'name_en',
                    'status',
                    'quantity',
                    'total_halalah',
                ])
                ->withExists('secret')
                ->orderBy('id')])
            ->firstOrFail();
        $terminal = in_array($order->status, [
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::Refunded,
        ], true);
        $placedAt = $order->getAttribute('placed_at') ?? $order->getAttribute('created_at');

        return [
            'id' => (string) $order->getAttribute('public_id'),
            'number' => (string) $order->getAttribute('order_number'),
            'status' => $order->status->value,
            'placedAt' => $placedAt instanceof CarbonInterface ? $placedAt->toIso8601String() : '',
            'total' => AccountMoney::fromMinor(
                (int) $order->getAttribute('total_halalah'),
                (string) $order->getAttribute('currency'),
            ),
            'refreshable' => ! $terminal,
            'paymentStartUrl' => $order->status === OrderStatus::PendingPayment
                ? route(
                    $locale === 'en'
                        ? 'localized.store.orders.paylink-payment'
                        : 'store.orders.paylink-payment',
                    [...($locale === 'en' ? ['locale' => 'en'] : []), 'order' => $publicId],
                    absolute: false,
                )
                : null,
            'items' => $order->items
                ->map(fn (OrderItem $item): array => [
                    'id' => (string) $item->getAttribute('public_id'),
                    'name' => (string) $item->getAttribute($locale === 'en' ? 'name_en' : 'name_ar'),
                    'status' => $item->status->value,
                    'quantity' => (int) $item->getAttribute('quantity'),
                    'total' => AccountMoney::fromMinor(
                        (int) $item->getAttribute('total_halalah'),
                        (string) $order->getAttribute('currency'),
                    ),
                    'credentialsPresent' => (bool) $item->getAttribute('secret_exists'),
                ])
                ->values()
                ->all(),
        ];
    }
}
