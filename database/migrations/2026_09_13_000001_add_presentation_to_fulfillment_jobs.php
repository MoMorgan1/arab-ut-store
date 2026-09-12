<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_jobs', function (Blueprint $table): void {
            $table->string('presentation')->nullable()->after('hold_reason');
            $table->string('hold_tone')->nullable()->after('presentation');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_jobs', function (Blueprint $table): void {
            $table->dropColumn(['hold_tone', 'presentation']);
        });
    }
};
