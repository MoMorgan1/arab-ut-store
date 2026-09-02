<?php

use App\Enums\OrderItemStatus;
use App\Enums\ServiceType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use Illuminate\Support\Facades\Schema;

function serviceReviewsMigration(): object
{
    return require database_path('migrations/2026_09_02_000004_add_service_type_to_reviews.php');
}

test('the migration backfills item-linked, single-service order, mixed order, and unlinked reviews', function () {
    $migration = serviceReviewsMigration();
    $migration->down();

    // Re-run up up to the point before backfill if we want to create rows, or run up() which includes backfill!
    // Since columns need to exist before we create Review with service_type, we can test by calling up() when reviews exist!
    // Let's create the schema by calling Schema::table without the backfill or by running migration up(), creating reviews with service_type = null, and re-running the migration up() logic!
    $migration->up();

    // Now rows can be created with service_type null
    $itemOrder = Order::factory()->create();
    $item = OrderItem::factory()->for($itemOrder)->create([
        'service_type' => ServiceType::Rivals,
        'status' => OrderItemStatus::Completed,
    ]);
    $itemLinkedReview = Review::create([
        'reviewer_name' => 'Item Reviewer',
        'rating' => 5,
        'order_item_id' => $item->id,
        'service_type' => null,
    ]);

    $singleServiceOrder = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $singleServiceOrder->id,
        'service_type' => ServiceType::FutChampions,
        'status' => OrderItemStatus::Completed,
    ]);
    $orderReview = Review::create([
        'reviewer_name' => 'Order Reviewer',
        'rating' => 5,
        'order_id' => $singleServiceOrder->id,
        'service_type' => null,
    ]);

    $mixedOrder = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $mixedOrder->id,
        'service_type' => ServiceType::Rivals,
        'status' => OrderItemStatus::Completed,
    ]);
    OrderItem::factory()->create([
        'order_id' => $mixedOrder->id,
        'service_type' => ServiceType::Sbc,
        'status' => OrderItemStatus::Completed,
    ]);
    $mixedOrderReview = Review::create([
        'reviewer_name' => 'Mixed Reviewer',
        'rating' => 5,
        'order_id' => $mixedOrder->id,
        'service_type' => null,
    ]);

    $unlinkedReview = Review::create([
        'reviewer_name' => 'Archive Reviewer',
        'rating' => 5,
        'service_type' => null,
    ]);

    // Rollback and run up() again to test full migration execution over existing rows
    $migration->down();
    $migration->up();

    expect($itemLinkedReview->fresh()->service_type)->toBe('rivals')
        ->and($orderReview->fresh()->service_type)->toBe('fut_champions')
        ->and($mixedOrderReview->fresh()->service_type)->toBeNull()
        ->and($unlinkedReview->fresh()->service_type)->toBeNull();
});

test('the migration rolls back cleanly', function () {
    $migration = serviceReviewsMigration();
    $migration->down();

    expect(Schema::hasColumn('reviews', 'service_type'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('reviews', 'service_type'))->toBeTrue();
});
