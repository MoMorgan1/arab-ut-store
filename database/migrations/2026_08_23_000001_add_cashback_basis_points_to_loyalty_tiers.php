<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_tiers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('cashback_basis_points')->default(0)->after('minimum_lifetime_spend_halalah');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_tiers', function (Blueprint $table): void {
            $table->dropColumn('cashback_basis_points');
        });
    }
};
