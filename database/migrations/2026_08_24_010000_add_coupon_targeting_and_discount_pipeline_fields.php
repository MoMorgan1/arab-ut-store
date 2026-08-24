<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->string('scope')->default('order')->index();
            $table->string('service_type')->nullable()->index();
            $table->boolean('first_order_only')->default(false);
            $table->boolean('excludes_promoted_items')->default(false);
        });

        Schema::create('coupon_targets', function (Blueprint $table): void {
            $table->id();
            // Every domain table carries a public_id; CouponTarget extends
            // DomainModel, which assigns one on create.
            $table->ulid('public_id')->unique();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['coupon_id', 'target_type', 'target_id']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::table('promotions', function (Blueprint $table): void {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('product_id')->nullable()->index();

                return;
            }

            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
        });

        if (DB::connection()->getDriverName() === 'mysql' || DB::connection()->getDriverName() === 'mariadb') {
            DB::statement("ALTER TABLE coupons ADD CONSTRAINT IF NOT EXISTS coupons_percent_value_check CHECK (discount_type != 'percent' OR (value >= 0 AND value <= 100))");
            DB::statement("ALTER TABLE promotions ADD CONSTRAINT IF NOT EXISTS promotions_percent_value_check CHECK (discount_type != 'percent' OR (value >= 0 AND value <= 90))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql' || DB::connection()->getDriverName() === 'mariadb') {
            DB::statement('ALTER TABLE coupons DROP CONSTRAINT IF EXISTS coupons_percent_value_check');
            DB::statement('ALTER TABLE promotions DROP CONSTRAINT IF EXISTS promotions_percent_value_check');
        }

        Schema::table('promotions', function (Blueprint $table): void {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->dropConstrainedForeignId('product_id');
            } else {
                $table->dropIndex(['product_id']);
                $table->dropColumn('product_id');
            }
        });

        Schema::dropIfExists('coupon_targets');

        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropIndex(['scope']);
            $table->dropIndex(['service_type']);
            $table->dropColumn(['scope', 'service_type', 'first_order_only', 'excludes_promoted_items']);
        });
    }
};
