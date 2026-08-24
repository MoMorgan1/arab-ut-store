<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->string('mechanic')->default('item')->index();
            $table->unsignedInteger('buy_quantity')->nullable();
            $table->unsignedInteger('get_quantity')->nullable();
            $table->unsignedInteger('max_applications')->nullable();
            $table->string('discount_target')->nullable()->default('cheapest');
            $table->string('qualifying_scope')->nullable();
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->rawColumn('bundle_price_halalah', 'integer check (bundle_price_halalah is null or (bundle_price_halalah between 0 and 9223372036854775807))')->nullable();
            } else {
                $table->unsignedBigInteger('bundle_price_halalah')->nullable();
            }
            $table->boolean('applies_to_promoted_items')->default(false);
        });

        Schema::create('promotion_components', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('product_id')->nullable()->index();
            } else {
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            }
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['promotion_id', 'product_id']);
        });

        if (DB::connection()->getDriverName() === 'mysql' || DB::connection()->getDriverName() === 'mariadb') {
            DB::statement('ALTER TABLE promotions ADD CONSTRAINT IF NOT EXISTS promotions_bundle_price_nonnegative CHECK (bundle_price_halalah IS NULL OR (bundle_price_halalah BETWEEN 0 AND 9223372036854775807))');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql' || DB::connection()->getDriverName() === 'mariadb') {
            DB::statement('ALTER TABLE promotions DROP CONSTRAINT IF EXISTS promotions_bundle_price_nonnegative');
        }

        Schema::dropIfExists('promotion_components');

        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropIndex(['mechanic']);
            $table->dropColumn([
                'mechanic',
                'buy_quantity',
                'get_quantity',
                'max_applications',
                'discount_target',
                'qualifying_scope',
                'bundle_price_halalah',
                'applies_to_promoted_items',
            ]);
        });
    }
};
