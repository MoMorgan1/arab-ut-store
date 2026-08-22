<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index(['role', 'created_at'], 'idx_users_admin_role_created_at');
            $table->index(['role', 'is_active'], 'idx_users_admin_role_is_active');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['user_id', 'status'], 'idx_orders_admin_user_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('idx_users_admin_role_created_at');
            $table->dropIndex('idx_users_admin_role_is_active');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('idx_orders_admin_user_status');
        });
    }
};
