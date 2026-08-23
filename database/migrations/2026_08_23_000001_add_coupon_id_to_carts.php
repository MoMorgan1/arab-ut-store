<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite rebuilds the table when a foreign key is added, which drops the
        // raw partial unique index (carts_one_active_owner). Add a plain indexed
        // column there and keep the real constraint for MySQL/MariaDB.
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('coupon_id')->nullable()->after('currency')->index();
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('carts', function (Blueprint $table) {
                $table->foreign('coupon_id')->references('id')->on('coupons')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('carts', function (Blueprint $table) {
                $table->dropForeign(['coupon_id']);
            });
        }

        Schema::table('carts', function (Blueprint $table) {
            $table->dropIndex(['coupon_id']);
            $table->dropColumn('coupon_id');
        });
    }
};
