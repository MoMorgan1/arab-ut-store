<?php

namespace App\Account\Queries;

use App\Account\Presenters\AccountMoney;
use App\Enums\OrderStatus;
use App\Enums\ServiceType;
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
                'discount_halalah',
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
                    'service_type',
                    'platform',
                    'status',
                    'quantity',
                    'total_halalah',
                    'configuration',
                ])
                ->withExists('secret')
                ->withExists('squadImage')
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
            'discount' => AccountMoney::fromMinor(
                (int) $order->getAttribute('discount_halalah'),
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
                    'manualFulfillment' => $this->manualFulfillment($item, $publicId, $locale),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function manualFulfillment(OrderItem $item, string $orderId, string $locale): ?array
    {
        if (! in_array($item->service_type, [ServiceType::FutChampions, ServiceType::Rivals], true)) {
            return null;
        }

        $configuration = is_array($item->configuration) ? $item->configuration : [];
        $localized = $locale === 'en';
        $routeParameters = [
            'order' => $orderId,
            'orderItem' => $item->public_id,
        ];
        $credentialsUrl = (bool) $item->getAttribute('secret_exists')
            ? route(
                $localized
                    ? 'localized.account.orders.items.credentials'
                    : 'account.orders.items.credentials',
                $routeParameters,
                absolute: false,
            )
            : null;
        $squadImageUrl = (bool) $item->getAttribute('squad_image_exists')
            ? route(
                $localized
                    ? 'localized.account.orders.items.squad-image'
                    : 'account.orders.items.squad-image',
                $routeParameters,
                absolute: false,
            )
            : null;

        return [
            'credentialsUrl' => $credentialsUrl,
            'squadImageUrl' => $squadImageUrl,
            'platform' => $item->platform->value,
            ...$this->safeManualConfiguration($configuration, $item->service_type),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, bool|int|string|null>
     */
    private function safeManualConfiguration(array $configuration, ServiceType $service): array
    {
        $safe = [];
        $launcher = $configuration['pc_store'] ?? null;

        if (in_array($launcher, ['ea_app', 'steam'], true)) {
            $safe['pcLauncher'] = $launcher;
        }

        if ($service === ServiceType::FutChampions) {
            $rank = $configuration['rank'] ?? null;
            $urgent = $configuration['urgent'] ?? null;
            $matchesPlayed = $configuration['matches_played'] ?? null;

            if (is_int($rank) && $rank >= 1 && $rank <= 6) {
                $safe['targetRank'] = $rank;
            }

            if (is_bool($urgent)) {
                $safe['urgent'] = $urgent;
            }

            if (is_int($matchesPlayed) && $matchesPlayed >= 0 && $matchesPlayed <= 100) {
                $safe['matchesPlayed'] = $matchesPlayed;
            }

            return $safe;
        }

        foreach (['current_division' => 'fromDivision', 'target_division' => 'toDivision'] as $key => $output) {
            $division = $configuration[$key] ?? null;

            if (is_string($division) && in_array($division, ['7', '6', '5', '4', '3', '2', '1', 'elite'], true)) {
                $safe[$output] = $division;
            }
        }

        return $safe;
    }
}
