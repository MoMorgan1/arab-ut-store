<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->index(['service_type', 'order_id'], 'idx_order_items_admin_service_order');
            $table->index(['platform', 'order_id'], 'idx_order_items_admin_platform_order');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['placed_at', 'id'], 'idx_orders_admin_placed_sort');
            $table->index(['total_halalah', 'id'], 'idx_orders_admin_total_sort');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['order_id', 'id'], 'idx_payments_admin_order_id_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('idx_order_items_admin_service_order');
            $table->dropIndex('idx_order_items_admin_platform_order');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('idx_orders_admin_placed_sort');
            $table->dropIndex('idx_orders_admin_total_sort');
        });

        Schema::table('payments', function (Blueprint $table): void {
            // The payments -> orders foreign key relies on this composite
            // index; leave a plain order_id index behind so dropping it
            // never violates the constraint on MariaDB.
            $table->index('order_id', 'idx_payments_order_id_fallback');
            $table->dropIndex('idx_payments_admin_order_id_lookup');
        });
    }
};
