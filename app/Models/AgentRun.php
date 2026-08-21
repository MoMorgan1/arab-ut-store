<?php

namespace App\Models;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AgentRunStatus $status
 * @property int $attempt_number
 * @property AgentErrorCode|null $error_code
 * @property string|null $provider_response_id
 * @property int|null $latency_ms
 * @property int|null $input_tokens
 * @property int|null $cached_input_tokens
 * @property int|null $cache_write_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $total_tokens
 * @property string|null $estimated_cost_usd
 */
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
