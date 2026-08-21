<?php

namespace App\Actions\AI;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\DB;

final readonly class EnsureAgentTurnTerminal
{
    public function execute(AgentTurn $turn): void
    {
        DB::transaction(function () use ($turn): void {
            $lockedConversation = ChatConversation::query()
                ->whereKey($turn->conversation_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTurn = AgentTurn::query()
                ->whereKey($turn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedTurn->status, [AgentTurnStatus::Completed, AgentTurnStatus::Failed, AgentTurnStatus::Cancelled], true)) {
                return;
            }

            $runningRun = AgentRun::query()
                ->where('agent_turn_id', $lockedTurn->id)
                ->where('status', AgentRunStatus::Running)
                ->lockForUpdate()
                ->first();

            if ($runningRun instanceof AgentRun) {
                $runningRun->forceFill([
                    'status' => AgentRunStatus::Failed,
                    'error_code' => AgentErrorCode::StreamTerminated,
                    'completed_at' => now(),
                ])->save();
            }

            $lockedTurn->forceFill([
                'status' => AgentTurnStatus::Failed,
                'terminal_error_code' => AgentErrorCode::StreamTerminated,
                'completed_at' => $lockedTurn->completed_at ?? now(),
            ])->save();
        }, 3);
    }
}
