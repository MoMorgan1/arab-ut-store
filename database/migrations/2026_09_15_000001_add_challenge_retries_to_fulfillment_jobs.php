<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the poll keeps count of its own challenge retries.
 *
 * Fulfillment v14 re-fired retrySBCAPI for a transient solve status on a
 * cadence it counted inside the run. The store's poll has no run to count in,
 * so the count lives on the job: per challenge id, how many reads in a row
 * showed a transient status and how many retries were sent. Cleared the
 * moment the status is no longer transient (owner decision 2026-09-15:
 * challenge retries stay automatic, unlike coins resumes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_jobs', function (Blueprint $table): void {
            $table->json('challenge_retries')->nullable()->after('allowed_actions');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_jobs', function (Blueprint $table): void {
            $table->dropColumn('challenge_retries');
        });
    }
};
