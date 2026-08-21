<?php

namespace App\Actions\AI;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Services\AI\AgentTurnRetryPolicy;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class PrepareAutomaticAgentRetry
{
    public function __construct(
        private AgentTurnRetryPolicy $retryPolicy,
    ) {}

    public function execute(AgentTurn $turn, AgentRun $run): AgentTurn
    {
        return DB::transaction(function () use ($turn, $run): AgentTurn {
            $lockedConversation = ChatConversation::query()
                ->whereKey($turn->conversation_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTurn = AgentTurn::query()
                ->whereKey($turn->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRun = AgentRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->retryPolicy->canAutomaticallyRetry($lockedTurn, $lockedRun, AgentErrorCode::RateLimited)) {
                throw new LogicException('Turn is not eligible for automatic retry.');
            }

            $lockedRun->forceFill([
                'status' => AgentRunStatus::Failed,
                'error_code' => AgentErrorCode::RateLimited,
                'completed_at' => now(),
            ])->save();

            $lockedTurn->forceFill([
                'status' => AgentTurnStatus::Waiting,
                'terminal_error_code' => null,
                'completed_at' => null,
            ])->save();

            return $lockedTurn->fresh();
        }, 3);
    }
}
