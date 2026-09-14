<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\PriceRun;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * An applied pricing run carrying the supplier cost table, the way the v2.4
 * workflow publishes it: USD per million per tier, dollars per euro.
 *
 * @param  array<string, mixed>  $observations
 */
function appliedPricingRun(array $observations = []): PriceRun
{
    return PriceRun::query()->create([
        'run_id' => (string) Str::ulid(),
        'event_id' => (string) Str::ulid(),
        'status' => 'applied',
        'mode' => 'apply',
        'pricing_version' => 12,
        'payload' => [
            'observations' => array_replace([
                'source' => 'fft+utt-v2',
                'ratioEuroUsd' => 1.15,
                'cyclePSUsdPerM' => 9.2,
                'cyclePCUsdPerM' => null,
                'tierCosts' => [
                    'console_fast' => [
                        ['targetK' => 1000, 'rawUsdPerM' => 11.0, 'source' => 'fft_targeted_ps'],
                        ['targetK' => 2000, 'rawUsdPerM' => 11.5, 'source' => 'utt_ps'],
                        ['targetK' => 5000, 'rawUsdPerM' => 13.0, 'source' => 'utt_ps'],
                        ['targetK' => 10000, 'rawUsdPerM' => 15.0, 'source' => 'utt_ps'],
                        ['targetK' => 15000, 'rawUsdPerM' => 15.0, 'source' => 'utt_ps'],
                        ['targetK' => 20000, 'rawUsdPerM' => 17.0, 'source' => 'utt_ps'],
                    ],
                    'pc' => [
                        ['targetK' => 1000, 'rawUsdPerM' => 25.0, 'source' => 'fft_targeted_pc'],
                        ['targetK' => 2000, 'rawUsdPerM' => 25.0, 'source' => 'fft_targeted_pc'],
                        ['targetK' => 5000, 'rawUsdPerM' => 26.0, 'source' => 'fft_targeted_pc'],
                        ['targetK' => 10000, 'rawUsdPerM' => 27.0, 'source' => 'fft_targeted_pc'],
                        ['targetK' => 15000, 'rawUsdPerM' => 27.0, 'source' => 'fft_targeted_pc'],
                        ['targetK' => 20000, 'rawUsdPerM' => 28.0, 'source' => 'fft_targeted_pc'],
                    ],
                ],
            ], $observations),
        ],
        'started_at' => now(),
        'completed_at' => now(),
    ]);
}

/** A paid storefront order with no items yet. */
function paidOrder(string $orderNumber = 'AUT-PAID-1001'): Order
{
    $user = User::factory()->create(['first_name' => 'Fahad', 'last_name' => 'Al-Otaibi']);

    return Order::factory()->for($user)->create([
        'order_number' => $orderNumber,
        'status' => OrderStatus::Received,
        'total_halalah' => 1250,
        'paid_at' => now(),
    ]);
}

/**
 * A received Coins item, with an EA account when a payload is given.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>|null  $secretPayload
 */
function coinsItem(Order $order, array $attributes = [], ?array $secretPayload = null): OrderItem
{
    $variant = ProductVariant::factory()->create([
        'service_type' => ServiceType::Coins,
        'platform' => $attributes['platform'] ?? Platform::PlayStation,
    ]);
    $item = OrderItem::factory()->for($order)->create(array_replace([
        'product_variant_id' => $variant->id,
        'service_type' => ServiceType::Coins,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::Received,
        'configuration' => [
            'service_type' => 'coins',
            'platform' => 'playstation',
            'market' => 'console',
            'delivery' => 'fast',
            'coins_quantity' => 1_250_000,
        ],
    ], $attributes));

    if ($secretPayload !== null) {
        $secret = new OrderItemSecret([
            'order_item_id' => $item->id,
            'masked_summary' => ['has_ea_password' => true, 'backup_code_count' => 3],
        ]);
        $secret->encrypted_payload = $secretPayload;
        $secret->save();
    }

    return $item;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function eaAccount(array $overrides = []): array
{
    return array_replace([
        'ea_email' => 'fahad@example.test',
        'ea_password' => 'safe password',
        'backup_codes' => ['11111111', '22222222', '33333333'],
        'current_balance' => 350_000,
    ], $overrides);
}

/**
 * An order the publisher can actually send: one Coins item with an EA
 * account, and an applied pricing run to budget against.
 */
function placeableOrder(string $orderNumber = 'AUT-PAID-1001'): Order
{
    if (! PriceRun::query()->where('status', 'applied')->exists()) {
        appliedPricingRun();
    }

    $order = paidOrder($orderNumber);
    coinsItem($order, secretPayload: eaAccount());

    return $order;
}
