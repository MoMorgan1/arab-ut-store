<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_secrets', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('deleted_at');
        });

        Schema::table('fulfillment_jobs', function (Blueprint $table): void {
            $table->unsignedInteger('credential_version_sent')->nullable()->after('poll_failure_count');
            $table->timestamp('credentials_sent_at')->nullable()->after('credential_version_sent');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_jobs', function (Blueprint $table): void {
            $table->dropColumn(['credentials_sent_at', 'credential_version_sent']);
        });

        Schema::table('order_item_secrets', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
