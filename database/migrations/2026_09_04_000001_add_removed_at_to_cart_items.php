<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->timestamp('removed_at')->nullable()->after('configuration');
            $table->index('removed_at');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropIndex(['removed_at']);
            $table->dropColumn('removed_at');
        });
    }
};
