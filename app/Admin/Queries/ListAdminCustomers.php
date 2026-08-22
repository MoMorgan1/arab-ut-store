<?php

namespace App\Admin\Queries;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @phpstan-type AdminCustomersFilters array{
 *     search?: ?string,
 *     status?: ?string,
 *     date_from?: ?string,
 *     date_to?: ?string,
 *     sort?: string,
 *     direction?: string,
 *     per_page?: int,
 *     page?: int
 * }
 * @phpstan-type AdminCustomerRow array{
 *     id: string,
 *     name: string,
 *     email: string,
 *     phone: ?string,
 *     isActive: bool,
 *     createdAt: string,
 *     ordersCount: int,
 *     lastOrderAt: ?string,
 *     totalSpent: array{amountMinor: string, currency: string},
 *     walletBalance: array{amountMinor: string, currency: string}
 * }
 */
final class ListAdminCustomers
{
    /**
     * @param  AdminCustomersFilters  $filters
     * @return array{
     *     customers: list<AdminCustomerRow>,
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

        $ordersCount = DB::table('orders')
            ->selectRaw('count(*)')
            ->whereColumn('orders.user_id', 'users.id');

        $lastOrderAt = DB::table('orders')
            ->selectRaw('max(placed_at)')
            ->whereColumn('orders.user_id', 'users.id');

        $totalSpent = DB::table('orders')
            ->selectRaw('coalesce(sum(total_halalah), 0)')
            ->whereColumn('orders.user_id', 'users.id')
            ->whereIn('orders.status', [
                OrderStatus::Received->value,
                OrderStatus::InProgress->value,
                OrderStatus::WaitingForCustomer->value,
                OrderStatus::Completed->value,
            ]);

        $walletBalance = DB::table('wallet_accounts')
            ->selectRaw('coalesce(balance_halalah, 0)')
            ->whereColumn('wallet_accounts.user_id', 'users.id');

        $paginator = $query->select([
            'users.id',
            'users.public_id',
            'users.first_name',
            'users.last_name',
            'users.email',
            'users.phone',
            'users.is_active',
            'users.created_at',
        ])
            ->selectSub($ordersCount, 'orders_count')
            ->selectSub($lastOrderAt, 'last_order_at')
            ->selectSub($totalSpent, 'total_spent')
            ->selectSub($walletBalance, 'wallet_balance')
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 15),
                page: (int) ($filters['page'] ?? 1),
            );

        $customerRows = array_values(array_map(
            fn (stdClass $user): stdClass => $user,
            $paginator->items(),
        ));

        return [
            'customers' => $this->projectCustomers($customerRows),
            'pagination' => $this->pagination($paginator),
        ];
    }

    /** @param AdminCustomersFilters $filters */
    private function filteredQuery(array $filters): Builder
    {
        $query = DB::table('users')
            ->where('users.role', UserRole::Customer->value);

        $this->applySearch($query, $filters['search'] ?? null);

        if (! empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->where('users.is_active', true);
            } elseif ($filters['status'] === 'suspended') {
                $query->where('users.is_active', false);
            }
        }

        $this->applyDateFilters(
            $query,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );

        $sortColumn = match ($filters['sort'] ?? 'created_at') {
            'name' => 'users.first_name',
            'orders_count' => 'orders_count',
            'last_order_at' => 'last_order_at',
            'total_spent' => 'total_spent',
            default => 'users.created_at',
        };
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortColumn, $direction)
            ->orderBy('users.id', $direction);
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        $lowercaseSearch = mb_strtolower($search);
        $phoneDigits = preg_replace('/\D+/', '', $search);

        $query->where(function (Builder $customerQuery) use ($search, $lowercaseSearch, $phoneDigits): void {
            $customerQuery->where('users.public_id', $search)
                ->orWhereRaw('LOWER(users.first_name) LIKE ?', ['%'.$lowercaseSearch.'%'])
                ->orWhereRaw('LOWER(users.last_name) LIKE ?', ['%'.$lowercaseSearch.'%'])
                ->orWhereRaw("LOWER(CONCAT(users.first_name, ' ', users.last_name)) LIKE ?", ['%'.$lowercaseSearch.'%'])
                ->orWhereRaw('LOWER(users.email) = ?', [$lowercaseSearch])
                ->orWhere('users.phone', $search);

            if ($phoneDigits !== '' && $phoneDigits !== null) {
                $customerQuery->orWhere('users.phone', $phoneDigits)
                    ->orWhere('users.phone', '+'.$phoneDigits)
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(users.phone, '+', ''), ' ', ''), '-', '') LIKE ?", ['%'.$phoneDigits.'%']);
            }
        });
    }

    private function applyDateFilters(
        Builder $query,
        ?string $dateFrom,
        ?string $dateTo,
    ): void {
        if (! empty($dateFrom)) {
            $start = Carbon::createFromFormat('Y-m-d', (string) $dateFrom, 'UTC')->startOfDay();
            $query->where('users.created_at', '>=', $start);
        }

        if (! empty($dateTo)) {
            $end = Carbon::createFromFormat('Y-m-d', (string) $dateTo, 'UTC')->addDay()->startOfDay();
            $query->where('users.created_at', '<', $end);
        }
    }

    /**
     * @param  list<stdClass>  $users
     * @return list<AdminCustomerRow>
     */
    private function projectCustomers(array $users): array
    {
        return array_map(function (stdClass $user): array {
            return [
                'id' => (string) $user->public_id,
                'name' => trim((string) $user->first_name.' '.(string) $user->last_name),
                'email' => (string) $user->email,
                'phone' => $user->phone !== null ? (string) $user->phone : null,
                'isActive' => (bool) $user->is_active,
                'createdAt' => $user->created_at !== null
                    ? Carbon::parse($user->created_at, 'UTC')->utc()->toIso8601String()
                    : '',
                'ordersCount' => (int) ($user->orders_count ?? 0),
                'lastOrderAt' => $user->last_order_at !== null
                    ? Carbon::parse($user->last_order_at, 'UTC')->utc()->toIso8601String()
                    : null,
                'totalSpent' => [
                    'amountMinor' => (string) ($user->total_spent ?? 0),
                    'currency' => 'SAR',
                ],
                'walletBalance' => [
                    'amountMinor' => (string) ($user->wallet_balance ?? 0),
                    'currency' => 'SAR',
                ],
            ];
        }, $users);
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
