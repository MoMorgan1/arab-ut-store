<?php

namespace App\Console\Commands;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Support\AI\AgentRuntimeConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RecoverStaleAgentTurns extends Command
{
    protected $signature = 'agent:recover-stale-turns';

    protected $description = 'Recover stale waiting and running agent turns and fail them safely';

    public function handle(AgentRuntimeConfig $config): int
    {
        $cutoff = $this->staleCutoff($config);

        $candidateIds = AgentTurn::query()
            ->whereIn('status', [AgentTurnStatus::Waiting, AgentTurnStatus::Running])
            ->where('updated_at', '<=', $cutoff)
            ->pluck('id');

        $recoveredCount = 0;

        foreach ($candidateIds as $candidateId) {
            if ($this->recoverStaleTurn((int) $candidateId, $config)) {
                $recoveredCount++;
            }
        }

        $this->components->info("Recovered {$recoveredCount} stale agent turn(s).");

        return self::SUCCESS;
    }

    /**
     * Agent turns store millisecond timestamps; the cutoff must carry the same
     * precision or SQLite text comparison rejects same-second candidates.
     */
    private function staleCutoff(AgentRuntimeConfig $config): string
    {
        return now()->subSeconds($config->staleTurnSeconds())->format('Y-m-d H:i:s.v');
    }

    private function recoverStaleTurn(int $turnId, AgentRuntimeConfig $config): bool
    {
        return DB::transaction(function () use ($turnId, $config): bool {
            $turn = AgentTurn::query()->find($turnId);

            if (! $turn instanceof AgentTurn) {
                return false;
            }

            $lockedConversation = ChatConversation::query()
                ->whereKey($turn->conversation_id)
                ->lockForUpdate()
                ->first();

            if (! $lockedConversation instanceof ChatConversation) {
                return false;
            }

            $lockedTurn = AgentTurn::query()
                ->whereKey($turn->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedTurn instanceof AgentTurn) {
                return false;
            }

            if (! in_array($lockedTurn->status, [AgentTurnStatus::Waiting, AgentTurnStatus::Running], true)) {
                return false;
            }

            $currentCutoff = $this->staleCutoff($config);

            if ($lockedTurn->getRawOriginal('updated_at') > $currentCutoff) {
                return false;
            }

            $lockedRun = AgentRun::query()
                ->where('agent_turn_id', $lockedTurn->id)
                ->where('status', AgentRunStatus::Running)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($lockedRun instanceof AgentRun && $lockedRun->status === AgentRunStatus::Running) {
                $lockedRun->forceFill([
                    'status' => AgentRunStatus::Failed,
                    'error_code' => AgentErrorCode::StaleTurnRecovered,
                    'completed_at' => now(),
                ])->save();
            }

            $lockedTurn->forceFill([
                'status' => AgentTurnStatus::Failed,
                'completed_at' => $lockedTurn->completed_at ?? now(),
                'terminal_error_code' => AgentErrorCode::StaleTurnRecovered,
            ])->save();

            return true;
        }, 3);
    }
}
