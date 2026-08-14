<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('provider_refund_id')->nullable()->unique();
            $table->json('provider_metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropUnique(['provider_refund_id']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'provider_refund_id', 'provider_metadata']);
        });
    }
};
