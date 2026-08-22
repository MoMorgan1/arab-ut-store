<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agent_turn_id')->constrained('agent_turns')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number');
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->string('provider_response_id', 128)->nullable()->unique();
            $table->string('status', 32);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('cached_input_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 12, 8)->nullable();
            $table->string('pricing_version', 64);
            $table->ulid('trace_id')->unique();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_turn_id', 'attempt_number'], 'uq_agent_runs_attempt');
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
