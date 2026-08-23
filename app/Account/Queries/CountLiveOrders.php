<?php

namespace App\Account\Queries;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
        /** @var array<string, int> $perStatus */
        $perStatus = Order::query()
            ->where('user_id', $user->getKey())
            ->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        return [
            'all' => array_sum($perStatus),
            'open' => $this->sum($perStatus, ReadLiveOrders::OPEN_STATUSES),
            'completed' => $this->sum($perStatus, ReadLiveOrders::COMPLETED_STATUSES),
        ];
    }

    /**
     * @param  array<string, int>  $perStatus
     * @param  list<string>  $statuses
     */
    private function sum(array $perStatus, array $statuses): int
    {
        $total = 0;

        foreach ($statuses as $status) {
            $total += $perStatus[$status] ?? 0;
        }

        return $total;
    }
}
