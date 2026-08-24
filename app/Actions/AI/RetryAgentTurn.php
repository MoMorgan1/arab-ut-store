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

            // Explicit retry is the one claim path that never goes through
            // CreateOrRecoverAgentTurn, so the claim-time handoff guard does not
            // cover it. Without this check a customer whose turn failed before
            // the handoff could tap the still-visible Retry pill after a human
            // took over and re-stream نواف into a thread a person owns — the
            // exact invariant this feature exists to hold. Design 3.3 allows an
            // already-streaming turn to finish; it does not allow a new stream
            // to start after takeover.
            //
            // Checked under the conversation lock this transaction already
            // holds, so a takeover racing the retry cannot slip between.
            if ($lockedConversation->handoff_state->isLive()) {
                throw new LogicException('A human owns this conversation; the assistant may not retry.');
            }

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
