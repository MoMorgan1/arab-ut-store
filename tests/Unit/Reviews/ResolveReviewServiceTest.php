<?php

use App\Enums\OrderItemStatus;
use App\Enums\ServiceType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Reviews\ResolveReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('forOrderItem returns the string value of the item service type', function () {
    $item = OrderItem::factory()->make([
        'service_type' => ServiceType::Rivals,
    ]);

    expect(ResolveReviewService::forOrderItem($item))->toBe('rivals');
});

test('forOrder returns the single service type for an order with homogeneous items', function () {
    $order = Order::factory()->create();
    OrderItem::factory()->count(2)->create([
        'order_id' => $order->id,
        'service_type' => ServiceType::FutChampions,
        'status' => OrderItemStatus::Completed,
    ]);

    expect(ResolveReviewService::forOrder($order))->toBe('fut_champions');
});

test('forOrder returns null for a mixed-service order', function () {
    $order = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'service_type' => ServiceType::Rivals,
        'status' => OrderItemStatus::Completed,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'service_type' => ServiceType::Sbc,
        'status' => OrderItemStatus::Completed,
    ]);

    expect(ResolveReviewService::forOrder($order))->toBeNull();
});

test('forOrder excludes cancelled and refunded items when attributing service', function () {
    $order = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'service_type' => ServiceType::Rivals,
        'status' => OrderItemStatus::Completed,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'service_type' => ServiceType::Sbc,
        'status' => OrderItemStatus::Cancelled,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'service_type' => ServiceType::FutChampions,
        'status' => OrderItemStatus::Refunded,
    ]);

    expect(ResolveReviewService::forOrder($order))->toBe('rivals');
});

test('forOrder returns null when order has no items or only cancelled/refunded items', function () {
    $emptyOrder = Order::factory()->create();
    expect(ResolveReviewService::forOrder($emptyOrder))->toBeNull();

    $cancelledOrder = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $cancelledOrder->id,
        'service_type' => ServiceType::Rivals,
        'status' => OrderItemStatus::Cancelled,
    ]);
    OrderItem::factory()->create([
        'order_id' => $cancelledOrder->id,
        'service_type' => ServiceType::FutChampions,
        'status' => OrderItemStatus::Refunded,
    ]);

    expect(ResolveReviewService::forOrder($cancelledOrder))->toBeNull();
});
