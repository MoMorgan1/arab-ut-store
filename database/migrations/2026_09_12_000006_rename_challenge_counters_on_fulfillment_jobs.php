<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renames challenges_solved and challenges_requested to squads_done and squads_total,
 * and adds solves_done and solves_total to fulfillment_jobs.
 *
 * This branch has never been deployed, so there is no production data to preserve
 * and no backfill to write. The rename reflects the real payload semantics where
 * challengesDone/totalChallenges represent squads in the solve currently being worked,
 * while timesSolved/timesToSolve represent the challenge solves purchased.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulfillment_jobs', function (Blueprint $table): void {
            $table->renameColumn('challenges_solved', 'squads_done');
            $table->renameColumn('challenges_requested', 'squads_total');
            $table->unsignedInteger('solves_done')->nullable()->after('squads_total');
            $table->unsignedInteger('solves_total')->nullable()->after('solves_done');
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_jobs', function (Blueprint $table): void {
            $table->dropColumn(['solves_total', 'solves_done']);
            $table->renameColumn('squads_total', 'challenges_requested');
            $table->renameColumn('squads_done', 'challenges_solved');
        });
    }
};
