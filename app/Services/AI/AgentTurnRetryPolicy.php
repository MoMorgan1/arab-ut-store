<?php

namespace App\Services\AI;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Support\AI\AgentRuntimeConfig;

final readonly class AgentTurnRetryPolicy
{
    public function __construct(
        private AgentRuntimeConfig $config,
    ) {}

    public function canRetry(AgentTurn $turn): bool
    {
        return $turn->status === AgentTurnStatus::Failed
            && $turn->assistant_message_id === null
            && $turn->attempt_count < $this->config->maxAttempts()
            && $turn->terminal_error_code instanceof AgentErrorCode
            && $turn->terminal_error_code->isTransient();
    }

    public function canAutomaticallyRetry(
        AgentTurn $turn,
        AgentRun $run,
        AgentErrorCode $code,
    ): bool {
        return $code === AgentErrorCode::RateLimited
            && $run->attempt_number === 1
            && $run->status === AgentRunStatus::Running
            && $turn->status === AgentTurnStatus::Running
            && $turn->assistant_message_id === null
            && $turn->attempt_count < $this->config->maxAttempts();
    }
}
