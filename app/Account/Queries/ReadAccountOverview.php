<?php

namespace App\Account\Queries;

use App\Account\Presenters\AccountMoney;
use App\Account\Presenters\LiveOrderCard;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Support\Facades\DB;

final readonly class ReadAccountOverview
{
    public function __construct(
        private ResolveLiveActionableOrder $actionableOrder,
        private ResolveLoyaltyProgress $loyaltyProgress,
        private LiveOrderCard $orderPresenter,
    ) {}

    /**
     * @return array{
     *     summary: array<string, mixed>,
     *     activeOrder: array<string, mixed>|null,
     *     recentOrders: list<array<string, mixed>>,
     *     loyalty: array<string, mixed>|null
     * }
     */
    public function for(User $user, string $locale): array
    {
        $activeOrder = $this->actionableOrder->for($user, $locale);

        return [
            'summary' => $this->summary($user),
            'activeOrder' => $activeOrder,
            'recentOrders' => $this->recentOrders($user, $locale),
            'loyalty' => $this->loyaltyProgress->for($user, $locale),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(User $user): array
    {
        $openStatuses = [
            OrderStatus::PendingPayment->value,
            OrderStatus::Received->value,
            OrderStatus::InProgress->value,
            OrderStatus::WaitingForCustomer->value,
        ];
        $placeholders = implode(', ', array_fill(0, count($openStatuses), '?'));
        $metrics = DB::table('orders')
            ->where('user_id', $user->id)
            ->selectRaw('COUNT(*) AS order_count')
            ->selectRaw("SUM(CASE WHEN status IN ({$placeholders}) THEN 1 ELSE 0 END) AS open_order_count", $openStatuses)
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_order_count', [OrderStatus::Completed->value])
            ->first();
        $walletBalance = WalletAccount::query()
            ->where('user_id', $user->id)
            ->value('balance_halalah');

        return [
            'orderCount' => (int) $metrics->order_count,
            'openOrderCount' => (int) $metrics->open_order_count,
            'completedOrderCount' => (int) $metrics->completed_order_count,
            'walletBalance' => is_int($walletBalance)
                ? AccountMoney::fromMinor($walletBalance, 'SAR')
                : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentOrders(User $user, string $locale): array
    {
        $orders = Order::query()
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
            ->where('user_id', $user->id)
            ->with(['items' => fn ($query) => $query
                ->select(['id', 'order_id', 'name_ar', 'name_en', 'status'])
                ->orderBy('id')])
            ->orderByRaw('COALESCE(orders.placed_at, orders.created_at) DESC')
            ->orderByDesc('orders.public_id')
            ->limit(3)
            ->get()
            ->map(fn (Order $order): array => $this->orderPresenter->for($order, $locale))
            ->values()
            ->all();

        return array_values($orders);
    }
}
