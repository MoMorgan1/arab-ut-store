<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Review;
use App\Services\Reviews\ResolveReviewService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->string('service_type')->nullable()->after('order_id');
            $table->index(
                ['service_type', 'is_visible', 'rating', 'published_at', 'id'],
                'idx_reviews_service_store_visible',
            );
        });

        DB::transaction(function (): void {
            Review::query()
                ->whereNotNull('order_id')
                ->orWhereNotNull('order_item_id')
                ->with(['orderItem', 'order.items'])
                ->chunkById(100, function ($reviews): void {
                    /** @var Review $review */
                    foreach ($reviews as $review) {
                        $resolved = null;
                        if ($review->order_item_id !== null && $review->orderItem instanceof OrderItem) {
                            $resolved = ResolveReviewService::forOrderItem($review->orderItem);
                        } elseif ($review->order_id !== null && $review->order instanceof Order) {
                            $resolved = ResolveReviewService::forOrder($review->order);
                        }

                        // A query-builder update leaves updated_at alone: the
                        // backfill is bookkeeping, not an edit of the review.
                        if ($review->service_type !== $resolved) {
                            Review::query()->whereKey($review->id)->update(['service_type' => $resolved]);
                        }
                    }
                });
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropIndex('idx_reviews_service_store_visible');
            $table->dropColumn('service_type');
        });
    }
};
