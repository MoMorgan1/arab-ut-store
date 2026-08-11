<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_runs', function (Blueprint $table): void {
            $table->string('mode')->default('apply')->after('status');
            $table->unsignedInteger('pricing_version')->nullable()->after('mode');
            $table->json('payload')->nullable()->after('pricing_version');
            $table->text('reason')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('price_runs', function (Blueprint $table): void {
            $table->dropColumn(['mode', 'pricing_version', 'payload', 'reason']);
        });
    }
};
