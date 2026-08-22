<?php

namespace App\Admin\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type AdminOrdersFilters array{
 *     search?: ?string,
 *     status?: ?string,
 *     service?: ?string,
 *     platform?: ?string,
 *     payment_status?: ?string,
 *     date_from?: ?string,
 *     date_to?: ?string,
 *     sort?: string,
 *     direction?: string,
 *     per_page?: int,
 *     page?: int
 * }
 * @phpstan-type AdminOrderRow array{
 *     id: string,
 *     orderNumber: string,
 *     customer: array{id: string, name: string, email: string, phone: ?string},
 *     status: string,
 *     serviceTypes: list<string>,
 *     platforms: list<string>,
 *     itemCount: int,
 *     latestPaymentStatus: ?string,
 *     total: array{amountMinor: string, currency: string},
 *     placedAt: string
 * }
 */
final class ListAdminOrders
{
    /**
     * @param  AdminOrdersFilters  $filters
     * @return array{
     *     orders: list<AdminOrderRow>,
     *     pagination: array{
     *         currentPage: int,
     *         lastPage: int,
     *         perPage: int,
     *         total: int,
     *         from: ?int,
     *         to: ?int
     *     }
     * }
     */
    public function paginate(array $filters): array
    {
        $query = $this->filteredQuery($filters);
        $latestPaymentStatus = DB::table('payments')
            ->select('status')
            ->whereColumn('payments.order_id', 'orders.id')
            ->orderByDesc('id')
            ->limit(1);

        $paginator = $query->select([
            'orders.id',
            'orders.public_id',
            'orders.order_number',
            'orders.user_id',
            'orders.status',
            'orders.total_halalah',
            'orders.currency',
            'orders.placed_at',
        ])->selectSub($latestPaymentStatus, 'latest_payment_status')
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 15),
                page: (int) ($filters['page'] ?? 1),
            );

        $orderRows = array_values(array_map(
            fn (stdClass $order): stdClass => $order,
            $paginator->items(),
        ));

        return [
            'orders' => $this->projectOrders(
                $orderRows,
                $this->usersById($orderRows),
                $this->itemsByOrderId($orderRows),
            ),
            'pagination' => $this->pagination($paginator),
        ];
    }

    /** @param AdminOrdersFilters $filters */
    private function filteredQuery(array $filters): Builder
    {
        $query = DB::table('orders');

        $this->applySearch($query, $filters['search'] ?? null);

        if (! empty($filters['status'])) {
            $query->where('orders.status', $filters['status']);
        }

        $this->applyItemFilters(
            $query,
            $filters['service'] ?? null,
            $filters['platform'] ?? null,
        );
        $this->applyPaymentFilter($query, $filters['payment_status'] ?? null);
        $this->applyDateFilters(
            $query,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );

        $sortColumn = match ($filters['sort'] ?? 'placed_at') {
            'total' => 'orders.total_halalah',
            'order_number' => 'orders.order_number',
            default => 'orders.placed_at',
        };
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortColumn, $direction)
            ->orderBy('orders.id', $direction);
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        $lowercaseSearch = mb_strtolower($search);
        $query->where(function (Builder $orderQuery) use ($search, $lowercaseSearch): void {
            $orderQuery->where('orders.order_number', $search)
                ->orWhere('orders.public_id', $search)
                ->orWhereExists(function (Builder $userQuery) use ($search, $lowercaseSearch): void {
                    $userQuery->select(DB::raw(1))
                        ->from('users')
                        ->whereColumn('users.id', 'orders.user_id')
                        ->where(function (Builder $contactQuery) use ($search, $lowercaseSearch): void {
                            $contactQuery->where('users.public_id', $search)
                                ->orWhereRaw('LOWER(users.email) = ?', [$lowercaseSearch])
                                ->orWhere('users.phone', $search);
                        });
                });
        });
    }

    private function applyItemFilters(
        Builder $query,
        ?string $service,
        ?string $platform,
    ): void {
        if (empty($service) && empty($platform)) {
            return;
        }

        $query->whereExists(function (Builder $itemQuery) use ($service, $platform): void {
            $itemQuery->select(DB::raw(1))
                ->from('order_items')
                ->whereColumn('order_items.order_id', 'orders.id');

            if (! empty($service)) {
                $itemQuery->where('order_items.service_type', $service);
            }

            if (! empty($platform)) {
                $itemQuery->where('order_items.platform', $platform);
            }
        });
    }

    private function applyPaymentFilter(Builder $query, ?string $paymentStatus): void
    {
        if (empty($paymentStatus)) {
            return;
        }

        $query->where(function (Builder $paymentQuery): void {
            $paymentQuery->select('status')
                ->from('payments')
                ->whereColumn('payments.order_id', 'orders.id')
                ->orderByDesc('id')
                ->limit(1);
        }, $paymentStatus);
    }

    private function applyDateFilters(
        Builder $query,
        ?string $dateFrom,
        ?string $dateTo,
    ): void {
        if (! empty($dateFrom)) {
            $start = Carbon::createFromFormat('Y-m-d', (string) $dateFrom, 'UTC')->startOfDay();
            $query->where('orders.placed_at', '>=', $start);
        }

        if (! empty($dateTo)) {
            $end = Carbon::createFromFormat('Y-m-d', (string) $dateTo, 'UTC')->addDay()->startOfDay();
            $query->where('orders.placed_at', '<', $end);
        }
    }

    /**
     * @param  list<stdClass>  $orders
     * @return array<int, stdClass>
     */
    private function usersById(array $orders): array
    {
        $userIds = array_values(array_unique(array_map(
            fn (stdClass $order): int => (int) $order->user_id,
            $orders,
        )));
        $users = [];

        foreach (DB::table('users')->whereIn('id', $userIds)->select([
            'id',
            'public_id',
            'first_name',
            'last_name',
            'email',
            'phone',
        ])->get() as $user) {
            $users[(int) $user->id] = $user;
        }

        return $users;
    }

    /**
     * @param  list<stdClass>  $orders
     * @return array<int, list<stdClass>>
     */
    private function itemsByOrderId(array $orders): array
    {
        $orderIds = array_map(
            fn (stdClass $order): int => (int) $order->id,
            $orders,
        );
        $itemsByOrderId = [];

        foreach (DB::table('order_items')->whereIn('order_id', $orderIds)->select([
            'order_id',
            'service_type',
            'platform',
        ])->orderBy('id')->get() as $item) {
            $itemsByOrderId[(int) $item->order_id][] = $item;
        }

        return $itemsByOrderId;
    }

    /**
     * @param  list<stdClass>  $orders
     * @param  array<int, stdClass>  $users
     * @param  array<int, list<stdClass>>  $itemsByOrderId
     * @return list<AdminOrderRow>
     */
    private function projectOrders(
        array $orders,
        array $users,
        array $itemsByOrderId,
    ): array {
        return array_map(function (stdClass $order) use ($users, $itemsByOrderId): array {
            $user = $users[(int) $order->user_id];
            $items = $itemsByOrderId[(int) $order->id] ?? [];

            return [
                'id' => (string) $order->public_id,
                'orderNumber' => (string) $order->order_number,
                'customer' => [
                    'id' => (string) $user->public_id,
                    'name' => trim((string) $user->first_name.' '.(string) $user->last_name),
                    'email' => (string) $user->email,
                    'phone' => $user->phone !== null ? (string) $user->phone : null,
                ],
                'status' => (string) $order->status,
                'serviceTypes' => $this->distinctItemValues($items, 'service_type'),
                'platforms' => $this->distinctItemValues($items, 'platform'),
                'itemCount' => count($items),
                'latestPaymentStatus' => $order->latest_payment_status !== null
                    ? (string) $order->latest_payment_status
                    : null,
                'total' => [
                    'amountMinor' => (string) $order->total_halalah,
                    'currency' => (string) $order->currency,
                ],
                'placedAt' => $order->placed_at !== null
                    ? Carbon::parse($order->placed_at, 'UTC')->utc()->toIso8601String()
                    : '',
            ];
        }, $orders);
    }

    /**
     * @param  list<stdClass>  $items
     * @return list<string>
     */
    private function distinctItemValues(array $items, string $column): array
    {
        return array_values(array_unique(array_map(
            fn (stdClass $item): string => (string) $item->{$column},
            $items,
        )));
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{currentPage: int, lastPage: int, perPage: int, total: int, from: ?int, to: ?int}
     */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
