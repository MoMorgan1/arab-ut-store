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
 * Until the Salla history import has landed (no completed order carries the
 * `salla_import` channel) the audited export figures from config are shown
 * instead, so a fresh environment or a not-yet-imported production database
 * never advertises a handful of live orders as the store's whole history.
 * That is what the 2026-08-09 Salla import decision asked for.
 */
final class StoreProofReader
{
    public const CACHE_KEY = 'store:proof:counts';

    public const CACHE_SECONDS = 900;

    /** The channel ImportSallaOrders stamps on every historical order it writes. */
    public const IMPORT_CHANNEL = 'salla_import';

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
            /** @var object{completed_orders: int|string, customers_served: int|string, imported_orders: int|string|null}|null $row */
            $row = DB::table('orders')
                ->where('status', OrderStatus::Completed->value)
                ->selectRaw('COUNT(*) as completed_orders, COUNT(DISTINCT user_id) as customers_served')
                ->selectRaw('SUM(CASE WHEN channel = ? THEN 1 ELSE 0 END) as imported_orders', [self::IMPORT_CHANNEL])
                ->first();

            $completedOrders = (int) ($row->completed_orders ?? 0);
            $customersServed = (int) ($row->customers_served ?? 0);
            $importedOrders = (int) ($row->imported_orders ?? 0);

            if ($importedOrders === 0) {
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
