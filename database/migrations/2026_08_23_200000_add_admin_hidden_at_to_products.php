<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An admin override for storefront visibility.
     *
     * `is_visible` and `archived_at` are owned by the catalog snapshot:
     * SyncCatalogSnapshot rewrites both on every run, so an admin edit to them
     * is silently reverted on the next sync. This column is never written by
     * ingestion, so an admin can take any product off the storefront - including
     * an automation-owned one - and have that decision survive.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('admin_hidden_at')->nullable()->after('archived_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('admin_hidden_at');
        });
    }
};
