<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_placements', function (Blueprint $table): void {
            $table->json('supplier_challenge_ids')->nullable()->after('supplier_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_placements', function (Blueprint $table): void {
            $table->dropColumn('supplier_challenge_ids');
        });
    }
};
