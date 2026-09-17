<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_observation_gaps', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('fulfillment_job_id')->constrained()->cascadeOnDelete();

            // The phase and supplier this gap was measured in, copied rather
            // than joined. A job's phase moves from coins to challenge while it
            // runs, so reading the phase off the job a month later would file
            // every coins gap under challenge - the one mistake that would make
            // the whole table say the opposite of the truth.
            $table->string('delivery_phase')->nullable();
            $table->string('supplier')->nullable();

            // Seconds between this landed observation and the one before it on
            // the same job. There is no row for a job's first observation:
            // nothing to measure from is not a gap of zero.
            $table->unsignedInteger('gap_seconds');

            // Whether this observation was news. Without it the table answers
            // "how often does a reading arrive", which is our own poll cadence
            // read back to us; with it, it also answers "how long does a job go
            // before it moves", which is the question the alarm actually asks.
            $table->boolean('state_changed');

            $table->timestamp('observed_at');

            // No created_at/updated_at: the row is one measurement, written
            // once and never touched, and observed_at is the only instant it
            // has. `secret_access_logs` is shaped the same way for the same
            // reason.

            // The distribution query: one phase over a window of observed_at.
            $table->index(['delivery_phase', 'observed_at']);
            // The prune, which knows nothing about phases.
            $table->index('observed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_observation_gaps');
    }
};
