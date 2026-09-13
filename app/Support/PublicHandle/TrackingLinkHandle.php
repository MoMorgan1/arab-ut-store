<?php

namespace App\Support\PublicHandle;

use App\Models\Order;
use App\Models\OrderTrackingLink;
use Carbon\CarbonImmutable;

/**
 * Turns the token segment of a tracking URL into an Order.
 *
 * Unlike OrderHandle, this resolves a capability rather than an identity: the
 * caller proves they hold the token, so there is no user to scope by. The lookup
 * is against the token's SHA-256 digest, never by iterating rows, so a request
 * answers in one indexed read and a stolen dump cannot be replayed.
 */
final class TrackingLinkHandle
{
    public static function resolve(string $token): ?Order
    {
        $link = OrderTrackingLink::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $link instanceof OrderTrackingLink || $link->revoked_at !== null) {
            return null;
        }

        $link->forceFill(['last_used_at' => CarbonImmutable::now()])->save();

        // The column selection is this feature's own narrow allowlist: nothing
        // about money, the account, the order's public_id, or the items'
        // public_ids is loaded, because none of it may reach the presenter.
        return Order::query()
            ->select(['id', 'order_number', 'status', 'placed_at', 'created_at'])
            ->with(['items' => fn ($items) => $items
                ->select([
                    'id',
                    'order_id',
                    'product_variant_id',
                    'name_ar',
                    'name_en',
                    'service_type',
                    'platform',
                    'status',
                    'quantity',
                    'configuration',
                ])
                ->with([
                    'productVariant' => fn ($variants) => $variants
                        ->select(['id', 'product_id'])
                        ->with(['product' => fn ($products) => $products
                            ->select(['id'])
                            ->with('media')]),
                    'fulfillmentJob' => fn ($jobs) => $jobs->select([
                        'id',
                        'order_item_id',
                        'supplier',
                        'supplier_order_id',
                        'delivery_phase',
                        'hold_reason',
                        'presentation',
                        'hold_tone',
                        'observation',
                        'allowed_actions',
                        'observation_supported',
                        'observed_at',
                        'completed_at',
                        'coins_delivered',
                        'coins_ordered',
                        'squads_done',
                        'squads_total',
                        'solves_done',
                        'solves_total',
                    ])->with(['placements' => fn ($placements) => $placements->select([
                        'id',
                        'fulfillment_job_id',
                        'supplier',
                        'delivery_phase',
                        'supplier_challenge_ids',
                    ])]),
                ])
                ->orderBy('id')])
            ->find($link->order_id);
    }
}
