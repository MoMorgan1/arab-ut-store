<?php

namespace App\Actions\AI;

use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Services\AI\AgentTurnRetryPolicy;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class RetryAgentTurn
{
    public function __construct(
        private AgentTurnRetryPolicy $retryPolicy,
    ) {}

    public function execute(AgentTurn $turn): AgentTurn
    {
        return DB::transaction(function () use ($turn): AgentTurn {
            $lockedConversation = ChatConversation::query()
                ->whereKey($turn->conversation_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTurn = AgentTurn::query()
                ->whereKey($turn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->retryPolicy->canRetry($lockedTurn)) {
                throw new LogicException('Turn is not eligible for explicit retry.');
            }

            $lockedTurn->forceFill([
                'status' => AgentTurnStatus::Waiting,
                'terminal_error_code' => null,
                'completed_at' => null,
            ])->save();

            return $lockedTurn->fresh();
        }, 3);
    }
}
