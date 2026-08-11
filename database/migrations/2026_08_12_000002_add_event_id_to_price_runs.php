<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_runs', function (Blueprint $table): void {
            $table->ulid('event_id')->nullable()->unique()->after('run_id');
        });
    }

    public function down(): void
    {
        Schema::table('price_runs', function (Blueprint $table): void {
            $table->dropUnique(['event_id']);
            $table->dropColumn('event_id');
        });
    }
};
