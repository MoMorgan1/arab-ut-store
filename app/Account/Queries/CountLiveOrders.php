<?php

namespace App\Account\Queries;

use App\Models\Order;
use App\Models\User;

final readonly class CountLiveOrders
{
    /**
     * Whole-account counts for the orders page filter chips. Uses the exact
     * status sets ReadLiveOrders filters by so chip counts always match rows.
     *
     * @return array{all: int, open: int, completed: int}
     */
    public function for(User $user): array
    {
        $open = ReadLiveOrders::OPEN_STATUSES;
        $completed = ReadLiveOrders::COMPLETED_STATUSES;

        $row = Order::query()
            ->where('user_id', $user->getKey())
            ->selectRaw(
                'COUNT(*) AS count_all, '
                .'SUM(CASE WHEN status IN ('.$this->placeholders($open).') THEN 1 ELSE 0 END) AS count_open, '
                .'SUM(CASE WHEN status IN ('.$this->placeholders($completed).') THEN 1 ELSE 0 END) AS count_completed',
                [...$open, ...$completed],
            )
            ->first();

        return [
            'all' => (int) ($row?->count_all ?? 0),
            'open' => (int) ($row?->count_open ?? 0),
            'completed' => (int) ($row?->count_completed ?? 0),
        ];
    }

    /** @param  list<string>  $values */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
