<?php

namespace App\Admin\Queries;

use App\Admin\Support\CapturedRevenueAmount;
use App\Enums\AdminPermission;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
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
     *     previousCapturedRevenue: array{amountMinor: string, currency: string},
     *     totalOrders: array{current: int, previous: int},
     *     newCustomers: array{current: int, previous: int},
     *     attentionCount: int,
     *     revenueTrend: list<array{date: string, amountMinor: string, currency: string}>,
     *     orderStatusDistribution: list<array{status: string, count: int}>,
     *     recentOrders: list<array{id: string, number: string, status: string, placedAt: string, total: array{amountMinor: string, currency: string}}>,
     *     oldestUnresolvedOrder: null|array{id: string, number: string, status: string, placedAt: string},
     *     recentAuditEvents: null|list<array{id: string, action: string, createdAt: string}>
     * }
     */
    public function for(User $actor, int $days): array
    {
        $windowEndsAt = now();
        $windowStartsAt = $windowEndsAt->copy()->subDays($days);
        $previousStartsAt = $windowStartsAt->copy()->subDays($days);

        $payments = $this->paymentOverview($previousStartsAt, $windowStartsAt, $windowEndsAt);
        $orderMetrics = $this->orderMetrics($windowStartsAt, $windowEndsAt);
        $refundMetrics = $this->refundMetrics($windowStartsAt, $windowEndsAt);
        $totalOrders = $this->totalOrders($previousStartsAt, $windowStartsAt, $windowEndsAt);
        $newCustomers = $this->newCustomers($previousStartsAt, $windowStartsAt, $windowEndsAt);
        $revenueTrend = $this->revenueTrend($windowStartsAt, $windowEndsAt);
        $orderStatusDistribution = $this->orderStatusDistribution($windowStartsAt, $windowEndsAt);
        $recentOrders = $this->recentOrders($windowStartsAt, $windowEndsAt);

        $attentionCount = $orderMetrics['waitingForCustomer']
            + $payments['metrics']['failed']
            + $refundMetrics['failed'];

        return [
            'rangeDays' => $days,
            'orders' => $orderMetrics,
            'payments' => $payments['metrics'],
            'refunds' => $refundMetrics,
            'capturedRevenue' => $payments['capturedRevenue'],
            'previousCapturedRevenue' => $payments['previousCapturedRevenue'],
            'totalOrders' => $totalOrders,
            'newCustomers' => $newCustomers,
            'attentionCount' => $attentionCount,
            'revenueTrend' => $revenueTrend,
            'orderStatusDistribution' => $orderStatusDistribution,
            'recentOrders' => $recentOrders,
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
            'received' => (int) ($metrics->received ?? 0),
            'inProgress' => (int) ($metrics->in_progress ?? 0),
            'waitingForCustomer' => (int) ($metrics->waiting ?? 0),
        ];
    }

    /**
     * @return array{
     *     metrics: array{pending: int, failed: int},
     *     capturedRevenue: array{amountMinor: string, currency: string},
     *     previousCapturedRevenue: array{amountMinor: string, currency: string}
     * }
     */
    private function paymentOverview(
        DateTimeInterface $previousStartsAt,
        DateTimeInterface $windowStartsAt,
        DateTimeInterface $windowEndsAt
    ): array {
        $metrics = DB::table('payments')
            ->where(function (Builder $query) use ($previousStartsAt, $windowStartsAt, $windowEndsAt): void {
                $query->where(function (Builder $pendingOrFailed) use ($windowStartsAt, $windowEndsAt): void {
                    $pendingOrFailed
                        ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])
                        ->whereBetween('created_at', [$windowStartsAt, $windowEndsAt]);
                })->orWhere(function (Builder $captured) use ($previousStartsAt, $windowEndsAt): void {
                    $captured
                        ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::Refunded->value])
                        ->where('paid_at', '>=', $previousStartsAt)
                        ->where('paid_at', '<=', $windowEndsAt);
                });
            })
            ->selectRaw('SUM(CASE WHEN status = ? AND created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS pending', [
                PaymentStatus::Pending->value, $windowStartsAt, $windowEndsAt,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? AND created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS failed', [
                PaymentStatus::Failed->value, $windowStartsAt, $windowEndsAt,
            ])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) AND paid_at BETWEEN ? AND ? THEN captured_halalah ELSE 0 END) AS current_captured', [
                PaymentStatus::Paid->value, PaymentStatus::Refunded->value, $windowStartsAt, $windowEndsAt,
            ])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) AND paid_at >= ? AND paid_at < ? THEN captured_halalah ELSE 0 END) AS previous_captured', [
                PaymentStatus::Paid->value, PaymentStatus::Refunded->value, $previousStartsAt, $windowStartsAt,
            ])
            ->first();

        return [
            'metrics' => [
                'pending' => (int) ($metrics->pending ?? 0),
                'failed' => (int) ($metrics->failed ?? 0),
            ],
            'capturedRevenue' => [
                'amountMinor' => $this->capturedRevenueAmount->fromDatabase($metrics->current_captured ?? null),
                'currency' => 'SAR',
            ],
            'previousCapturedRevenue' => [
                'amountMinor' => $this->capturedRevenueAmount->fromDatabase($metrics->previous_captured ?? null),
                'currency' => 'SAR',
            ],
        ];
    }

    /** @return array{failed: int} */
    private function refundMetrics(DateTimeInterface $windowStartsAt, DateTimeInterface $windowEndsAt): array
    {
        return [
            'failed' => DB::table('refunds')
                ->where('status', 'failed')
                ->whereBetween('created_at', [$windowStartsAt, $windowEndsAt])
                ->count(),
        ];
    }

    /** @return array{current: int, previous: int} */
    private function totalOrders(
        DateTimeInterface $previousStartsAt,
        DateTimeInterface $windowStartsAt,
        DateTimeInterface $windowEndsAt
    ): array {
        $counts = DB::table('orders')
            ->where('placed_at', '>=', $previousStartsAt)
            ->where('placed_at', '<=', $windowEndsAt)
            ->selectRaw('SUM(CASE WHEN placed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS current_total', [
                $windowStartsAt, $windowEndsAt,
            ])
            ->selectRaw('SUM(CASE WHEN placed_at >= ? AND placed_at < ? THEN 1 ELSE 0 END) AS previous_total', [
                $previousStartsAt, $windowStartsAt,
            ])
            ->first();

        return [
            'current' => (int) ($counts->current_total ?? 0),
            'previous' => (int) ($counts->previous_total ?? 0),
        ];
    }

    /** @return array{current: int, previous: int} */
    private function newCustomers(
        DateTimeInterface $previousStartsAt,
        DateTimeInterface $windowStartsAt,
        DateTimeInterface $windowEndsAt
    ): array {
        $counts = DB::table('users')
            ->where('role', UserRole::Customer->value)
            ->where('created_at', '>=', $previousStartsAt)
            ->where('created_at', '<=', $windowEndsAt)
            ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS current_count', [
                $windowStartsAt, $windowEndsAt,
            ])
            ->selectRaw('SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS previous_count', [
                $previousStartsAt, $windowStartsAt,
            ])
            ->first();

        return [
            'current' => (int) ($counts->current_count ?? 0),
            'previous' => (int) ($counts->previous_count ?? 0),
        ];
    }

    /** @return list<array{date: string, amountMinor: string, currency: string}> */
    private function revenueTrend(DateTimeInterface $windowStartsAt, DateTimeInterface $windowEndsAt): array
    {
        $startDate = Carbon::parse($windowStartsAt, 'UTC')->utc()->startOfDay();
        $endDate = Carbon::parse($windowEndsAt, 'UTC')->utc()->startOfDay();

        $dailyTotals = [];
        $cursor = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $dailyTotals[$cursor->format('Y-m-d')] = '0';
            $cursor->addDay();
        }

        $rows = DB::table('payments')
            ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::Refunded->value])
            ->whereBetween('paid_at', [$windowStartsAt, $windowEndsAt])
            ->selectRaw('DATE(paid_at) AS payment_date, SUM(captured_halalah) AS captured_sum')
            ->groupByRaw('DATE(paid_at)')
            ->get();

        foreach ($rows as $row) {
            $dateKey = (string) $row->payment_date;
            if (array_key_exists($dateKey, $dailyTotals)) {
                $dailyTotals[$dateKey] = $this->capturedRevenueAmount->fromDatabase($row->captured_sum);
            }
        }

        $trend = [];
        foreach ($dailyTotals as $date => $amountMinor) {
            $trend[] = [
                'date' => $date,
                'amountMinor' => $amountMinor,
                'currency' => 'SAR',
            ];
        }

        return $trend;
    }

    /** @return list<array{status: string, count: int}> */
    private function orderStatusDistribution(DateTimeInterface $windowStartsAt, DateTimeInterface $windowEndsAt): array
    {
        $statusCounts = [];
        foreach (OrderStatus::cases() as $case) {
            $statusCounts[$case->value] = 0;
        }

        $rows = DB::table('orders')
            ->whereBetween('placed_at', [$windowStartsAt, $windowEndsAt])
            ->selectRaw('status, COUNT(*) AS status_count')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $statusKey = (string) $row->status;
            if (array_key_exists($statusKey, $statusCounts)) {
                $statusCounts[$statusKey] = (int) $row->status_count;
            }
        }

        $distribution = [];
        foreach ($statusCounts as $status => $count) {
            $distribution[] = [
                'status' => $status,
                'count' => $count,
            ];
        }

        return $distribution;
    }

    /** @return list<array{id: string, number: string, status: string, placedAt: string, total: array{amountMinor: string, currency: string}}> */
    private function recentOrders(DateTimeInterface $windowStartsAt, DateTimeInterface $windowEndsAt): array
    {
        $orders = DB::table('orders')
            ->whereBetween('placed_at', [$windowStartsAt, $windowEndsAt])
            ->select(['public_id', 'order_number', 'status', 'placed_at', 'total_halalah'])
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return array_values(
            $orders->map(fn (object $order): array => [
                'id' => (string) $order->public_id,
                'number' => (string) $order->order_number,
                'status' => (string) $order->status,
                'placedAt' => Carbon::parse($order->placed_at, 'UTC')->utc()->toIso8601String(),
                'total' => [
                    'amountMinor' => (string) ($order->total_halalah ?? 0),
                    'currency' => 'SAR',
                ],
            ])->all(),
        );
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

        // The overview contract intentionally allowlists no audit metadata.
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
