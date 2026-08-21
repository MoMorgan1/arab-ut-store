<?php

namespace App\Admin\Queries;

use App\Admin\Support\CapturedRevenueAmount;
use App\Enums\AdminPermission;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReadAdminOverview
{
    public function __construct(private readonly CapturedRevenueAmount $capturedRevenueAmount) {}

    /**
     * @return array{
     *     rangeDays: int,
     *     orders: array{received: int, inProgress: int, waitingForCustomer: int},
     *     payments: array{pending: int, failed: int},
     *     refunds: array{failed: int},
     *     capturedRevenue: array{amountMinor: string, currency: string},
     *     oldestUnresolvedOrder: null|array{id: string, number: string, status: string, placedAt: string},
     *     recentAuditEvents: null|list<array{id: string, action: string, createdAt: string}>
     * }
     */
    public function for(User $actor, int $days): array
    {
        $windowEndsAt = now();
        $windowStartsAt = $windowEndsAt->subDays($days);
        $payments = $this->paymentOverview($windowStartsAt, $windowEndsAt);

        return [
            'rangeDays' => $days,
            'orders' => $this->orderMetrics($windowStartsAt, $windowEndsAt),
            'payments' => $payments['metrics'],
            'refunds' => $this->refundMetrics($windowStartsAt, $windowEndsAt),
            'capturedRevenue' => $payments['capturedRevenue'],
            'oldestUnresolvedOrder' => $this->oldestUnresolvedOrder($windowEndsAt),
            'recentAuditEvents' => $this->recentAuditEvents($actor),
        ];
    }

    /** @return array{received: int, inProgress: int, waitingForCustomer: int} */
    private function orderMetrics(DateTimeInterface $windowStartsAt, DateTimeInterface $windowEndsAt): array
    {
        $metrics = DB::table('orders')
            ->whereIn('status', [
                OrderStatus::Received->value,
                OrderStatus::InProgress->value,
                OrderStatus::WaitingForCustomer->value,
            ])
            ->whereBetween('placed_at', [$windowStartsAt, $windowEndsAt])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS received', [OrderStatus::Received->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS in_progress', [OrderStatus::InProgress->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS waiting', [OrderStatus::WaitingForCustomer->value])
            ->first();

        return [
            'received' => (int) $metrics->received,
            'inProgress' => (int) $metrics->in_progress,
            'waitingForCustomer' => (int) $metrics->waiting,
        ];
    }

    /**
     * @return array{
     *     metrics: array{pending: int, failed: int},
     *     capturedRevenue: array{amountMinor: string, currency: string}
     * }
     */
    private function paymentOverview(DateTimeInterface $windowStartsAt, DateTimeInterface $windowEndsAt): array
    {
        $metrics = DB::table('payments')
            ->where(function (Builder $query) use ($windowStartsAt, $windowEndsAt): void {
                $query->where(function (Builder $pendingOrFailed) use ($windowStartsAt, $windowEndsAt): void {
                    $pendingOrFailed
                        ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])
                        ->whereBetween('created_at', [$windowStartsAt, $windowEndsAt]);
                })->orWhere(function (Builder $captured) use ($windowStartsAt, $windowEndsAt): void {
                    $captured
                        ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::Refunded->value])
                        ->whereBetween('paid_at', [$windowStartsAt, $windowEndsAt]);
                });
            })
            ->selectRaw('SUM(CASE WHEN status = ? AND created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS pending', [
                PaymentStatus::Pending->value, $windowStartsAt, $windowEndsAt,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? AND created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS failed', [
                PaymentStatus::Failed->value, $windowStartsAt, $windowEndsAt,
            ])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) AND paid_at BETWEEN ? AND ? THEN captured_halalah ELSE 0 END) AS captured', [
                PaymentStatus::Paid->value, PaymentStatus::Refunded->value, $windowStartsAt, $windowEndsAt,
            ])
            ->first();

        return [
            'metrics' => [
                'pending' => (int) $metrics->pending,
                'failed' => (int) $metrics->failed,
            ],
            'capturedRevenue' => [
                'amountMinor' => $this->capturedRevenueAmount->fromDatabase($metrics->captured),
                'currency' => 'SAR',
            ],
        ];
    }

    /** @return array{failed: int} */
    private function refundMetrics(DateTimeInterface $windowStartsAt, DateTimeInterface $windowEndsAt): array
    {
        return ['failed' => DB::table('refunds')
            ->where('status', 'failed')
            ->whereBetween('created_at', [$windowStartsAt, $windowEndsAt])
            ->count()];
    }

    /** @return null|array{id: string, number: string, status: string, placedAt: string} */
    private function oldestUnresolvedOrder(DateTimeInterface $windowEndsAt): ?array
    {
        $order = DB::table('orders')
            ->whereIn('status', [
                OrderStatus::PendingPayment->value,
                OrderStatus::Received->value,
                OrderStatus::InProgress->value,
                OrderStatus::WaitingForCustomer->value,
            ])
            ->whereRaw('COALESCE(placed_at, created_at) <= ?', [$windowEndsAt])
            ->select(['public_id', 'order_number', 'status'])
            ->selectRaw('COALESCE(placed_at, created_at) AS activity_at')
            ->orderByRaw('COALESCE(placed_at, created_at)')
            ->orderBy('id')
            ->first();

        if ($order === null) {
            return null;
        }

        return [
            'id' => (string) $order->public_id,
            'number' => (string) $order->order_number,
            'status' => (string) $order->status,
            'placedAt' => Carbon::parse($order->activity_at, 'UTC')->utc()->toIso8601String(),
        ];
    }

    /** @return null|list<array{id: string, action: string, createdAt: string}> */
    private function recentAuditEvents(User $actor): ?array
    {
        if (! $actor->can(AdminPermission::AuditView->value)) {
            return null;
        }

        // The first overview contract intentionally allowlists no audit metadata.
        $events = DB::table('staff_audit_logs')
            ->select(['public_id', 'action', 'created_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (object $event): array => [
                'id' => (string) $event->public_id,
                'action' => (string) $event->action,
                'createdAt' => Carbon::parse($event->created_at, 'UTC')->utc()->toIso8601String(),
            ])
            ->all();

        return array_values($events);
    }
}
