<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            // One review per order. SQLite and MariaDB both allow many NULLs
            // under a unique index, so the Salla archive rows are unaffected.
            $table->unique('order_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('review_invited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            // SQLite has no ALTER TABLE DROP CONSTRAINT; the foreign key leaves
            // with the column, exactly as the other migrations here handle it.
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['order_id']);
            }

            $table->dropUnique(['order_id']);
            $table->dropColumn('order_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('review_invited_at');
        });
    }
};
