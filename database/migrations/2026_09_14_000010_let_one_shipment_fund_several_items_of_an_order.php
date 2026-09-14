<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One supplier shipment may fund several items of the same order.
 *
 * Fulfillment v14 merged a challenge's coins and the coins bought beside it
 * into one supplier order on purpose - two shipments to one EA account at
 * once are two bots on one account - and ship-coins keeps that (owner
 * decision, 2026-09-14). The store then records the same supplier reference
 * on each item the shipment funds, so the database-level "one reference, one
 * placement" rule has to go. The rule that survives - a reference may only be
 * shared by items of the same order, in the same phase - is enforced by
 * RecordSupplierPlacement, which holds the order lock while it checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_placements', function (Blueprint $table): void {
            $table->dropUnique(['supplier', 'supplier_order_id']);
            $table->index(['supplier', 'supplier_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_placements', function (Blueprint $table): void {
            $table->dropIndex(['supplier', 'supplier_order_id']);
            $table->unique(['supplier', 'supplier_order_id']);
        });
    }
};
