<?php

namespace App\Support;

use App\Enums\ServiceType;

/**
 * Single source of truth for the order-item configuration allowlist.
 *
 * Checkout writes order-item configuration through this projection and the
 * Admin detail presenter reads it back through the same matrix, so a key can
 * only ever be persisted when it is also safe to display.
 */
final class SafeOrderItemConfiguration
{
    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public static function project(array $configuration, ServiceType $service): array
    {
        return array_intersect_key($configuration, array_flip(self::keys($service)));
    }

    /** @return list<string> */
    public static function keys(ServiceType $service): array
    {
        $keys = ['service_type', 'platform', 'market', 'quoted_at', 'price_version'];

        if ($service === ServiceType::Coins) {
            array_push($keys, 'delivery', 'coins_quantity');
        } elseif ($service === ServiceType::Sbc) {
            $keys[] = 'completion_count';
        } elseif ($service === ServiceType::FutChampions) {
            array_push($keys, 'pc_store', 'schedule_version', 'rank', 'urgent', 'matches_played');
        } elseif ($service === ServiceType::Rivals) {
            array_push($keys, 'pc_store', 'schedule_version', 'mode', 'current_division', 'target_division', 'included_wins');
        }

        return $keys;
    }
}
