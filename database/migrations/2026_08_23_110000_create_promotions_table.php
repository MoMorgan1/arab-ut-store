<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promotions')) {
            Schema::create('promotions', function (Blueprint $table) {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->string('name_ar');
                $table->string('name_en');
                $table->string('badge_ar')->nullable();
                $table->string('badge_en')->nullable();
                $table->string('scope')->default('all')->index();
                $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
                $table->string('service_type')->nullable()->index();
                $table->string('discount_type');
                $this->nonnegativeMoneyColumn($table, 'value');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        Schema::table('order_items', function (Blueprint $table): void {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('promotion_id')->nullable()->index();
                $table->rawColumn('promotion_discount_halalah', 'integer check (promotion_discount_halalah between 0 and 9223372036854775807)')->default(0);

                return;
            }

            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('promotion_discount_halalah')->default(0);
        });

        if (DB::connection()->getDriverName() === 'mysql' || DB::connection()->getDriverName() === 'mariadb') {
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT IF NOT EXISTS order_items_promotion_discount_halalah_nonnegative CHECK (promotion_discount_halalah BETWEEN 0 AND 9223372036854775807)');
            DB::statement('ALTER TABLE promotions ADD CONSTRAINT IF NOT EXISTS promotions_value_nonnegative CHECK (value BETWEEN 0 AND 9223372036854775807)');
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->dropConstrainedForeignId('promotion_id');
            } else {
                $table->dropIndex(['promotion_id']);
            }

            $table->dropColumn('promotion_discount_halalah');
        });

        Schema::dropIfExists('promotions');
    }

    private function nonnegativeMoneyColumn(Blueprint $table, string $column): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $table->rawColumn($column, "integer check ({$column} between 0 and 9223372036854775807)");

            return;
        }

        $table->bigInteger($column);
    }
};
