<?php

namespace App\Admin\Queries;

use App\Enums\AdminPermission;
use App\Models\Order;
use App\Models\StaffAuditLog;
use App\Models\User;

final class ReadAdminOrderDetail
{
    /**
     * @return array{
     *     order: Order,
     *     auditLogs: list<StaffAuditLog>|null
     * }|null
     */
    public function findByPublicId(string $publicId, User $actor): ?array
    {
        /** @var Order|null $order */
        $order = Order::query()
            ->where('public_id', $publicId)
            ->with([
                'user',
                'items' => fn ($query) => $query->orderBy('id'),
                'items.secret' => fn ($query) => $query->select(['id', 'order_item_id', 'public_id', 'masked_summary']),
                'items.statusHistory' => fn ($query) => $query->orderBy('id'),
                'items.statusHistory.actor',
                'payments' => fn ($query) => $query->orderByDesc('id'),
                'refunds' => fn ($query) => $query->orderByDesc('id'),
                'discounts' => fn ($query) => $query->orderBy('id'),
                'statusHistory' => fn ($query) => $query->whereNull('order_item_id')->orderByDesc('id'),
                'statusHistory.actor',
            ])
            ->first();

        if ($order === null) {
            return null;
        }

        $auditLogs = null;

        if ($actor->can(AdminPermission::AuditView->value)) {
            /** @var list<StaffAuditLog> $auditLogs */
            $auditLogs = StaffAuditLog::query()
                ->where('auditable_type', $order->getMorphClass())
                ->where('auditable_id', $order->getKey())
                ->with('actor')
                ->orderByDesc('id')
                ->get()
                ->all();
        }

        return [
            'order' => $order,
            'auditLogs' => $auditLogs,
        ];
    }
}
