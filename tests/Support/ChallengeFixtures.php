<?php

use App\Enums\DeliveryPhase;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Enums\Supplier;
use App\Models\CatalogSource;
use App\Models\FulfillmentJob;
use App\Models\FulfillmentPlacement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSecret;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * A challenge item on an order: the product carries the EasySBC set id the
 * way the catalogue sync writes it, and the item an EA account when a
 * payload is given.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>|null  $secretPayload
 */
function challengeItem(Order $order, array $attributes = [], ?array $secretPayload = null, int $setId = 412): OrderItem
{
    $product = Product::factory()->create([
        'service_type' => ServiceType::Sbc,
        'source_id' => CatalogSource::factory(),
        'external_id' => 'easysbc-sbc-'.$setId,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'service_type' => ServiceType::Sbc,
        'platform' => $attributes['platform'] ?? Platform::PlayStation,
    ]);
    $item = OrderItem::factory()->for($order)->create(array_replace([
        'product_variant_id' => $variant->id,
        'service_type' => ServiceType::Sbc,
        'platform' => Platform::PlayStation,
        'status' => OrderItemStatus::InProgress,
        'quantity' => 1,
        'configuration' => ['service_type' => 'sbc', 'platform' => 'playstation', 'market' => 'console', 'completion_count' => 2],
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
 * The item's coins-phase job with its funding placement, as ship-coins
 * leaves it once the shipment is reported.
 *
 * @param  array<string, mixed>  $jobAttributes
 */
function fundedChallengeJob(OrderItem $item, array $jobAttributes = [], Supplier $supplier = Supplier::Utt, string $reference = '574339'): FulfillmentJob
{
    $job = FulfillmentJob::factory()->create(array_replace([
        'order_item_id' => $item->id,
        'status' => FulfillmentStatus::InProgress,
        'supplier' => $supplier,
        'supplier_order_id' => $reference,
        'delivery_phase' => DeliveryPhase::Coins,
        'next_poll_at' => now(),
    ], $jobAttributes));

    FulfillmentPlacement::query()->create([
        'fulfillment_job_id' => $job->id,
        'delivery_phase' => DeliveryPhase::Coins,
        'supplier' => $supplier,
        'supplier_order_id' => $reference,
        'supplier_challenge_ids' => null,
        'idempotency_key' => 'fulfillment-placement:'.$item->public_id.':coins',
        'placed_at' => now(),
    ]);

    return $job;
}
