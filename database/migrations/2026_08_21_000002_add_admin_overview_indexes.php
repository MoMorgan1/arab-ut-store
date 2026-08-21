<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'placed_at', 'id'], 'idx_orders_admin_status_activity');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['status', 'paid_at', 'id'], 'idx_payments_admin_status_paid');
        });
        Schema::table('refunds', function (Blueprint $table): void {
            $table->index(['status', 'created_at', 'id'], 'idx_refunds_admin_status_created');
        });
        Schema::table('staff_audit_logs', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'idx_staff_audits_admin_created');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('idx_orders_admin_status_activity');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('idx_payments_admin_status_paid');
        });
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropIndex('idx_refunds_admin_status_created');
        });
        Schema::table('staff_audit_logs', function (Blueprint $table): void {
            $table->dropIndex('idx_staff_audits_admin_created');
        });
    }
};
