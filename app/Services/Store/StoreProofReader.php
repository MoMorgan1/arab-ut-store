<?php

namespace App\Services\Store;

use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The two homepage proof figures that are counted rather than asserted.
 *
 * "Customers served" is the number of distinct accounts with at least one
 * completed order and "completed orders" is the number of those orders,
 * live and Salla-imported alike, since the import writes completed history
 * into the same orders table. The counts are cached briefly because the
 * homepage is the busiest page and the figures only ever creep upward.
 *
 * An empty database (a fresh environment, or one the import has not reached)
 * shows the audited export figures from config instead of "+0", which is what
 * the 2026-08-09 Salla import decision asked for.
 */
final class StoreProofReader
{
    public const CACHE_KEY = 'store:proof:counts';

    public const CACHE_SECONDS = 900;

    /**
     * Fill the `value` of every metric-driven hero stat, leaving fixed stats untouched.
     *
     * @param  list<array<string, mixed>>  $stats
     * @return list<array{value: string, unit: string, label: string}>
     */
    public function heroStats(array $stats): array
    {
        $counts = $this->counts();
        $filled = [];

        foreach ($stats as $stat) {
            $metric = $stat['metric'] ?? null;

            if (is_string($metric)) {
                if (! array_key_exists($metric, $counts)) {
                    throw new LogicException("Unknown store proof metric [{$metric}].");
                }

                $stat['value'] = '+'.number_format($counts[$metric]);
                unset($stat['metric']);
            }

            if (! is_string($stat['value'] ?? null) || ! is_string($stat['unit'] ?? null) || ! is_string($stat['label'] ?? null)) {
                throw new LogicException('Every hero stat must carry a string value, unit, and label.');
            }

            $filled[] = ['value' => $stat['value'], 'unit' => $stat['unit'], 'label' => $stat['label']];
        }

        return $filled;
    }

    /** @return array{customers_served: int, completed_orders: int} */
    public function counts(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            /** @var object{completed_orders: int|string, customers_served: int|string}|null $row */
            $row = DB::table('orders')
                ->where('status', OrderStatus::Completed->value)
                ->selectRaw('COUNT(*) as completed_orders, COUNT(DISTINCT user_id) as customers_served')
                ->first();

            $completedOrders = (int) ($row->completed_orders ?? 0);
            $customersServed = (int) ($row->customers_served ?? 0);

            if ($completedOrders === 0) {
                return [
                    'customers_served' => Config::integer('store.proof.fallback.customers_served'),
                    'completed_orders' => Config::integer('store.proof.fallback.completed_orders'),
                ];
            }

            return [
                'customers_served' => $customersServed,
                'completed_orders' => $completedOrders,
            ];
        });
    }
}
