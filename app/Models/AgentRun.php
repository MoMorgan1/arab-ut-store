<?php

namespace App\Models;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRun extends DomainModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AgentRunStatus::class,
            'attempt_number' => 'integer',
            'latency_ms' => 'integer',
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'total_tokens' => 'integer',
            'estimated_cost_usd' => 'decimal:8',
            'error_code' => AgentErrorCode::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AgentTurn, $this> */
    public function turn(): BelongsTo
    {
        return $this->belongsTo(AgentTurn::class, 'agent_turn_id');
    }
}
