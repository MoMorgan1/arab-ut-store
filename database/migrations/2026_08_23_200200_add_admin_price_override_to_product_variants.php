<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admin price override for automation-priced variants.
     *
     * The catalog snapshot rewrites price_halalah, sale_price_halalah and the
     * completionPricing tiers inside `configuration` on every run, so an admin
     * edit to those is reverted on the next sync - and for money that failure
     * mode means quietly charging the wrong amount. These two columns are never
     * written by ingestion; while they are set they win at read time.
     *
     * Both move together: SbcCompletionPricing requires the first tier total to
     * equal the variant's effective price, so a base price without its tier
     * table is not a valid override.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedInteger('admin_price_halalah')->nullable()->after('sale_price_halalah');
            $table->json('admin_completion_pricing')->nullable()->after('configuration');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_admin_price_positive CHECK (admin_price_halalah IS NULL OR admin_price_halalah > 0)');
        }
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn(['admin_price_halalah', 'admin_completion_pricing']);
        });
    }
};
