<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('mfa_revocation')->default(0)->after('two_factor_confirmed_at');
        });

        // SQLite applies one schema change per ALTER, so the drop waits for its
        // own statement rather than sharing the blueprint.
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('mfa_invalidated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('mfa_invalidated_at')->nullable()->after('two_factor_confirmed_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('mfa_revocation');
        });
    }
};
