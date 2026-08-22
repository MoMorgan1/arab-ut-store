<?php

namespace App\Actions\AI;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\DB;

final readonly class FailAgentTurn
{
    public function execute(
        AgentTurn $turn,
        ?AgentRun $run,
        AgentErrorCode $errorCode,
    ): void {
        DB::transaction(function () use ($turn, $run, $errorCode): void {
            $lockedConversation = ChatConversation::query()
                ->whereKey($turn->conversation_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTurn = AgentTurn::query()
                ->whereKey($turn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTurn->status === AgentTurnStatus::Completed) {
                return;
            }

            if ($run instanceof AgentRun) {
                $lockedRun = AgentRun::query()
                    ->whereKey($run->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedRun instanceof AgentRun && $lockedRun->status === AgentRunStatus::Running) {
                    $lockedRun->forceFill([
                        'status' => AgentRunStatus::Failed,
                        'error_code' => $errorCode,
                        'completed_at' => now(),
                    ])->save();
                }
            }

            $lockedTurn->forceFill([
                'status' => AgentTurnStatus::Failed,
                'completed_at' => $lockedTurn->completed_at ?? now(),
                'terminal_error_code' => $errorCode,
            ])->save();
        }, 3);
    }
}
