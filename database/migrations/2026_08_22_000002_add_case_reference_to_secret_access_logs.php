<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secret_access_logs', function (Blueprint $table): void {
            $table->string('case_reference', 64)->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('secret_access_logs', function (Blueprint $table): void {
            $table->dropColumn('case_reference');
        });
    }
};
