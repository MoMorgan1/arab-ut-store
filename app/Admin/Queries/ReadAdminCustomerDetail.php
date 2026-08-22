<?php

namespace App\Admin\Queries;

use App\Enums\AdminPermission;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\StaffAuditLog;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;

final class ReadAdminCustomerDetail
{
    /**
     * @return array{
     *     user: User,
     *     ordersSummary: array{
     *         ordersCount: int,
     *         totalSpent: int,
     *         lastOrderAt: ?string
     *     },
     *     recentOrders: list<Order>,
     *     walletSummary: array{
     *         balance: int,
     *         entriesCount: int
     *     },
     *     recentWalletEntries: list<WalletEntry>,
     *     auditLogs: list<StaffAuditLog>|null
     * }|null
     */
    public function findByPublicId(string $publicId, User $actor): ?array
    {
        /** @var User|null $user */
        $user = User::query()
            ->where('public_id', $publicId)
            ->where('role', UserRole::Customer)
            ->first();

        if ($user === null) {
            return null;
        }

        $ordersCount = Order::query()
            ->where('user_id', $user->id)
            ->count();

        $totalSpent = (int) Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                OrderStatus::Received,
                OrderStatus::InProgress,
                OrderStatus::WaitingForCustomer,
                OrderStatus::Completed,
            ])
            ->sum('total_halalah');

        $lastOrderAt = Order::query()
            ->where('user_id', $user->id)
            ->whereNotNull('placed_at')
            ->max('placed_at');

        /** @var list<Order> $recentOrders */
        $recentOrders = Order::query()
            ->where('user_id', $user->id)
            ->select([
                'id',
                'public_id',
                'order_number',
                'status',
                'total_halalah',
                'currency',
                'placed_at',
            ])
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->all();

        /** @var WalletAccount|null $walletAccount */
        $walletAccount = WalletAccount::query()
            ->where('user_id', $user->id)
            ->first();

        $balance = $walletAccount instanceof WalletAccount ? (int) $walletAccount->balance_halalah : 0;
        $entriesCount = 0;
        $recentWalletEntries = [];

        if ($walletAccount instanceof WalletAccount) {
            $entriesCount = WalletEntry::query()
                ->where('wallet_account_id', $walletAccount->id)
                ->count();

            /** @var list<WalletEntry> $recentWalletEntries */
            $recentWalletEntries = WalletEntry::query()
                ->where('wallet_account_id', $walletAccount->id)
                ->select([
                    'id',
                    'public_id',
                    'wallet_account_id',
                    'type',
                    'amount_halalah',
                    'balance_after_halalah',
                    'reference',
                    'metadata',
                    'created_at',
                ])
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->all();
        }

        $auditLogs = null;

        if ($actor->can(AdminPermission::AuditView->value)) {
            /** @var list<StaffAuditLog> $auditLogs */
            $auditLogs = StaffAuditLog::query()
                ->where('auditable_type', $user->getMorphClass())
                ->where('auditable_id', $user->getKey())
                ->with('actor')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->all();
        }

        return [
            'user' => $user,
            'ordersSummary' => [
                'ordersCount' => $ordersCount,
                'totalSpent' => $totalSpent,
                'lastOrderAt' => $lastOrderAt,
            ],
            'recentOrders' => $recentOrders,
            'walletSummary' => [
                'balance' => $balance,
                'entriesCount' => $entriesCount,
            ],
            'recentWalletEntries' => $recentWalletEntries,
            'auditLogs' => $auditLogs,
        ];
    }
}
