<?php

namespace App\Actions\AI;

use App\Enums\AI\AgentProvider;
use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Support\AI\AgentRuntimeConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class StartAgentRun
{
    public function __construct(
        private AgentRuntimeConfig $config,
    ) {}

    public function execute(AgentTurn $turn, AgentProvider $provider): AgentRun
    {
        return DB::transaction(function () use ($turn, $provider): AgentRun {
            $lockedConversation = ChatConversation::query()
                ->whereKey($turn->conversation_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTurn = AgentTurn::query()
                ->whereKey($turn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTurn->status !== AgentTurnStatus::Waiting) {
                throw new LogicException('Cannot start a run for an agent turn that is not waiting.');
            }

            if ($lockedTurn->assistant_message_id !== null) {
                throw new LogicException('Cannot start a run for an agent turn that already has an assistant message.');
            }

            $attemptNumber = $lockedTurn->attempt_count + 1;

            if ($attemptNumber > $this->config->maxAttempts()) {
                throw new LogicException('Agent turn attempt budget exceeded.');
            }

            $run = AgentRun::query()->create([
                'agent_turn_id' => $lockedTurn->id,
                'attempt_number' => $attemptNumber,
                'provider' => $provider->value,
                'model' => $this->config->model(),
                'status' => AgentRunStatus::Running,
                'pricing_version' => $this->config->pricingVersion(),
                'trace_id' => (string) Str::ulid(),
                'started_at' => now(),
            ]);

            $lockedTurn->forceFill([
                'status' => AgentTurnStatus::Running,
                'attempt_count' => $attemptNumber,
                'started_at' => $lockedTurn->started_at ?? now(),
                'terminal_error_code' => null,
            ])->save();

            return $run;
        }, 3);
    }
}
