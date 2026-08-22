<?php

namespace App\Admin\Presenters;

use App\Models\Order;
use App\Models\StaffAuditLog;
use App\Models\User;
use App\Models\WalletEntry;

final readonly class AdminCustomerDetailPage
{
    public function __construct(
        private AdminShell $shell,
        private AdminCustomerDetail $detailPresenter,
    ) {}

    /**
     * @param array{
     *     user: User,
     *     ordersSummary: array{ordersCount: int, totalSpent: int, lastOrderAt: ?string},
     *     recentOrders: list<Order>,
     *     walletSummary: array{balance: int, entriesCount: int},
     *     recentWalletEntries: list<WalletEntry>,
     *     auditLogs: list<StaffAuditLog>|null
     * } $detail
     * @return array<string, mixed>
     */
    public function for(
        User $actor,
        string $locale,
        array $detail,
    ): array {
        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $presented = $this->detailPresenter->present(
            $detail['user'],
            $detail['ordersSummary'],
            $detail['recentOrders'],
            $detail['walletSummary'],
            $detail['recentWalletEntries'],
            $detail['auditLogs'],
            $locale,
        );

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'customer' => $presented,
            'statusUrl' => route($prefix.'customers.status.store', ['publicId' => (string) $detail['user']->public_id], absolute: false),
            'confirmPasswordUrl' => route('password.confirm', absolute: false),
        ];
    }
}
