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

            // No public_id. Every other table here carries one because
            // something outside the database names its rows - a customer, an
            // admin screen, a webhook. Nothing names one of these: there is no
            // route, no panel and no API that refers to a single measurement,
            // and a unique ULID index would be a write on every insert for an
            // identifier with no reader.

            // Nullable and nulled on delete rather than cascaded. The
            // measurement outlives its subject on purpose: a distribution is
            // built from what happened, and losing a fortnight of baseline
            // because the jobs behind it were tidied up is how the table
            // quietly stops being able to answer the question it exists for.
            $table->foreignId('fulfillment_job_id')->nullable()->constrained()->nullOnDelete();

            // The phase, supplier and band this gap was measured in, copied
            // rather than joined. A job's phase moves from coins to challenge
            // and its band moves with the customer's attention, so reading any
            // of the three off the job a month later would file the
            // measurement under whatever it happens to be then.
            $table->string('delivery_phase')->nullable();
            $table->string('supplier')->nullable();
            $table->string('band');

            // Seconds between this landed observation and the one before it on
            // the same job. There is no row for a job's first observation:
            // nothing to measure from is not a gap of zero.
            $table->unsignedInteger('gap_seconds');

            // Whether this reading brought news - a different state string, or
            // any progress counter advanced. Both halves matter: a shipment
            // that delivered another 50,000 coins under an unchanged status
            // told us something, and string inequality alone would file it as
            // silence.
            $table->boolean('moved');

            // What the poller's own backoff was doing at the time. Without
            // these the table can describe how long gaps were but never why,
            // and a per-band threshold could not be told apart from a backoff
            // artefact after the fact.
            $table->unsignedInteger('poll_failure_count');

            // The counters behind `moved`, so it can be recomputed if the
            // definition of "news" changes. Null where the supplier's answer
            // did not carry them.
            $table->unsignedBigInteger('coins_delivered')->nullable();
            $table->unsignedInteger('squads_done')->nullable();
            $table->unsignedInteger('solves_done')->nullable();

            $table->timestamp('observed_at');

            // No created_at/updated_at: the row is one measurement, written
            // once and never touched, and observed_at is the only instant it
            // has. `secret_access_logs` is shaped the same way for the same
            // reason.

            // Counting a window of one phase or one band.
            $table->index(['delivery_phase', 'observed_at']);
            $table->index(['band', 'observed_at']);
            // The prune, which knows nothing about phases.
            $table->index('observed_at');
            // The percentile reads, which are `ORDER BY gap_seconds` with an
            // OFFSET inside one phase or one supplier. Without these they are
            // a filesort over the whole retained window, roughly seventy times
            // per run of the report.
            $table->index(['delivery_phase', 'gap_seconds']);
            $table->index(['supplier', 'gap_seconds']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_observation_gaps');
    }
};
