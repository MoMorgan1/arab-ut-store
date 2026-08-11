<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->string('source_key')->nullable()->after('source');
            $table->string('external_id')->nullable()->after('source_key');
            $table->char('content_hash', 64)->nullable()->after('external_id');
            $table->unique(['source_key', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropUnique(['source_key', 'external_id']);
            $table->dropColumn(['source_key', 'external_id', 'content_hash']);
        });
    }
};
