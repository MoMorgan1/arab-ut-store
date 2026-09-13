<?php

namespace App\Admin\Support;

use Illuminate\Database\Query\Builder;

/**
 * One definition of what "search for a customer" means in the admin.
 *
 * Extracted when the manual-order drawer needed the same search the customers
 * screen already had. Two copies would have drifted the moment one of them
 * learned a new way to spell a Saudi phone number, and the person searching
 * would have had no way to know which screen knew the trick.
 */
final class CustomerSearchPredicate
{
    /**
     * Matches a customer number with or without its `CUS-` prefix, a first or
     * last name or the two together, an exact email, and a phone written any
     * of the ways a person types one.
     *
     * @param  Builder  $query  A query already scoped to the `users` table.
     */
    public static function apply(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        $lowercaseSearch = mb_strtolower($search);
        $phoneDigits = preg_replace('/\D+/', '', $search);

        $query->where(function (Builder $customerQuery) use ($search, $lowercaseSearch, $phoneDigits): void {
            $customerQuery->whereRaw('LOWER(users.customer_number) = ?', [$lowercaseSearch])
                ->orWhereRaw('LOWER(users.customer_number) = ?', ['cus-'.$lowercaseSearch])
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
}
