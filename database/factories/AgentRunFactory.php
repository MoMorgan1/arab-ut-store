<?php

namespace Database\Factories;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AgentRun> */
class AgentRunFactory extends Factory
{
    protected $model = AgentRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_turn_id' => AgentTurn::factory(),
            'attempt_number' => 1,
            'provider' => 'fake',
            'model' => 'fake-support',
            'provider_response_id' => null,
            'status' => AgentRunStatus::Running,
            'latency_ms' => null,
            'input_tokens' => null,
            'cached_input_tokens' => null,
            'cache_write_tokens' => null,
            'output_tokens' => null,
            'reasoning_tokens' => null,
            'total_tokens' => null,
            'estimated_cost_usd' => null,
            'pricing_version' => 'fake-v1',
            'trace_id' => (string) Str::ulid(),
            'error_code' => null,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => AgentRunStatus::Running,
            'latency_ms' => null,
            'error_code' => null,
            'completed_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => AgentRunStatus::Completed,
            'latency_ms' => 1,
            'error_code' => null,
            'completed_at' => now(),
        ]);
    }

    public function failed(AgentErrorCode $errorCode): static
    {
        return $this->state(fn () => [
            'status' => AgentRunStatus::Failed,
            'latency_ms' => 1,
            'error_code' => $errorCode,
            'completed_at' => now(),
        ]);
    }
}
