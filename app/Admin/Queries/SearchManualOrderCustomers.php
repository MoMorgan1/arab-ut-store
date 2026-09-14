<?php

namespace App\Admin\Queries;

use App\Admin\Support\CustomerSearchPredicate;
use App\Enums\UserRole;
use App\Support\PublicHandle\CustomerHandle;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Finds the customer a manual order is being written for.
 *
 * Deliberately its own query rather than a reuse of `ListAdminCustomers`: that
 * one is a management screen and returns money spent, tier, status history and
 * last-order dates. Staff hold `orders.create` without holding
 * `customers.view`, so this returns only the four things needed to recognise a
 * person in a picker - and nothing a support agent would otherwise not be
 * allowed to read.
 */
final readonly class SearchManualOrderCustomers
{
    /**
     * Customers only. `CreateManualOrder:70` refuses an order written against
     * a staff or service account, so offering one here would only produce a
     * failure two screens later.
     */
    private const LIMIT = 8;

    /**
     * @return list<array{handle: string, name: string, email: string, phone: string|null, ordersCount: int, isActive: bool}>
     */
    public function execute(?string $search): array
    {
        $search = trim((string) $search);

        // An unfiltered search would page the whole customer table into a
        // picker. Two characters is the shortest thing worth asking about.
        if (mb_strlen($search) < 2) {
            return [];
        }

        $query = DB::table('users')
            ->where('users.role', UserRole::Customer->value)
            ->select([
                'users.customer_number',
                'users.public_id',
                'users.first_name',
                'users.last_name',
                'users.email',
                'users.phone',
                'users.is_active',
            ])
            ->selectSub(
                DB::table('orders')->selectRaw('count(*)')->whereColumn('orders.user_id', 'users.id'),
                'orders_count',
            );

        CustomerSearchPredicate::apply($query, $search);

        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('users.first_name')->limit(self::LIMIT)->get()->all();

        return array_map(
            fn (stdClass $row): array => [
                'handle' => CustomerHandle::handleForValues(
                    $row->customer_number === null ? null : (string) $row->customer_number,
                    (string) $row->public_id,
                ),
                'name' => trim((string) $row->first_name.' '.(string) $row->last_name),
                'email' => (string) $row->email,
                'phone' => $row->phone === null ? null : (string) $row->phone,
                'ordersCount' => (int) $row->orders_count,
                'isActive' => (bool) $row->is_active,
            ],
            $rows,
        );
    }
}
