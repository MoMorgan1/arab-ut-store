<?php

use App\Enums\DeliveryPhase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_placements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('fulfillment_job_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_phase');
            $table->string('supplier');
            $table->string('supplier_order_id');
            $table->string('idempotency_key')->unique();
            $table->timestamp('placed_at');
            $table->timestamps();
            $table->unique(['fulfillment_job_id', 'delivery_phase']);
            $table->unique(['supplier', 'supplier_order_id']);
        });

        $this->backfillFirstPlacements();

        // Placements now own supplier-reference uniqueness across every phase.
        // The job mirror only advertises the first placement, so a unique index
        // there would refuse the second phase of a challenge item.
        Schema::table('fulfillment_jobs', function (Blueprint $table): void {
            $table->dropUnique(['supplier', 'supplier_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('fulfillment_jobs', function (Blueprint $table): void {
            $table->unique(['supplier', 'supplier_order_id']);
        });

        Schema::dropIfExists('fulfillment_placements');
    }

    /**
     * Every job bound before placements existed already holds the identity of
     * its first placement, so copy each bound job into one placement row.
     *
     * No application code ever inserted a fulfillment job before this branch
     * (the placement endpoint is the first writer), so on our data this is
     * expected to find nothing. It stays because the table has existed since
     * August and a manually inserted row is possible: deleting the backfill
     * would silently strand that row's supplier reference, which is the exact
     * failure this migration closes.
     */
    private function backfillFirstPlacements(): void
    {
        $jobs = DB::table('fulfillment_jobs')
            ->join('order_items', 'order_items.id', '=', 'fulfillment_jobs.order_item_id')
            ->whereNotNull('fulfillment_jobs.supplier')
            ->whereNotNull('fulfillment_jobs.supplier_order_id')
            ->orderBy('fulfillment_jobs.id')
            ->get([
                'fulfillment_jobs.id as job_id',
                'fulfillment_jobs.supplier as supplier',
                'fulfillment_jobs.supplier_order_id as supplier_order_id',
                'fulfillment_jobs.delivery_phase as delivery_phase',
                'fulfillment_jobs.created_at as created_at',
                'order_items.public_id as order_item_public_id',
            ]);

        foreach ($jobs as $job) {
            $phase = is_string($job->delivery_phase) && $job->delivery_phase !== ''
                ? $job->delivery_phase
                : DeliveryPhase::Coins->value;

            $now = now();

            DB::table('fulfillment_placements')->insert([
                'public_id' => (string) Str::ulid(),
                'fulfillment_job_id' => (int) $job->job_id,
                'delivery_phase' => $phase,
                'supplier' => (string) $job->supplier,
                'supplier_order_id' => (string) $job->supplier_order_id,
                'idempotency_key' => 'fulfillment-placement:'.(string) $job->order_item_public_id.':'.$phase,
                'placed_at' => is_string($job->created_at) ? $job->created_at : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
