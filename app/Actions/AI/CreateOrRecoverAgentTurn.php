<?php

namespace App\Actions\AI;

use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Queries\AI\PendingAgentMessages;
use App\Support\AI\AgentRuntimeConfig;
use App\ValueObjects\AI\AgentTurnClaim;
use App\ValueObjects\Chat\ChatOwner;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class CreateOrRecoverAgentTurn
{
    public function __construct(
        private AgentRuntimeConfig $config,
        private PendingAgentMessages $pendingAgentMessages,
    ) {}

    public function execute(
        ChatConversation $conversation,
        ChatOwner $owner,
    ): AgentTurnClaim {
        try {
            return DB::transaction(
                fn (): AgentTurnClaim => $this->claimInTransaction((int) $conversation->id, $owner),
                3,
            );
        } catch (QueryException $exception) {
            if (! $this->isTurnContention($exception)) {
                throw $exception;
            }

            return $this->recoverCanonicalTurn((int) $conversation->id, $owner, $exception);
        }
    }

    private function claimInTransaction(int $conversationId, ChatOwner $owner): AgentTurnClaim
    {
        $conversation = $this->lockConversation($conversationId, $owner);
        $active = $this->lockActiveTurn($conversation);

        if ($active instanceof AgentTurn) {
            return $this->existingClaim($conversation, $active);
        }

        return $this->claimPendingRange($conversation);
    }

    private function claimPendingRange(ChatConversation $conversation): AgentTurnClaim
    {
        $cursor = (int) (AgentTurn::query()
            ->where('conversation_id', $conversation->id)
            ->max('last_customer_message_id') ?? 0);
        $pending = $this->pendingAgentMessages->query($conversation, $cursor);
        $latestPending = (clone $pending)->orderByDesc('id')->first();

        if (! $latestPending instanceof ChatMessage) {
            return AgentTurnClaim::idle();
        }

        $debounceUntil = $latestPending->created_at->copy()->addMilliseconds(
            $this->config->turnDebounceMilliseconds(),
        );

        if (now()->lt($debounceUntil)) {
            return AgentTurnClaim::waiting(max(1, (int) ceil(now()->diffInMilliseconds($debounceUntil))));
        }

        return $this->createTurn($conversation, $pending, $debounceUntil);
    }

    /** @param Builder<ChatMessage> $pending */
    private function createTurn(
        ChatConversation $conversation,
        Builder $pending,
        CarbonInterface $debounceUntil,
    ): AgentTurnClaim {
        $claimed = (clone $pending)
            ->orderBy('id')
            ->limit($this->config->maxContextMessages())
            ->get();
        $turn = AgentTurn::query()->create([
            'conversation_id' => $conversation->id,
            'status' => AgentTurnStatus::Waiting,
            'first_customer_message_id' => $claimed->firstOrFail()->id,
            'last_customer_message_id' => $claimed->last()->id,
            'debounce_until' => $debounceUntil,
            'prompt_version' => $this->config->promptVersion(),
            'attempt_count' => 0,
        ]);

        return AgentTurnClaim::created(
            $turn,
            $this->pendingAgentMessages->existsAfter($conversation, (int) $turn->last_customer_message_id),
        );
    }

    private function recoverCanonicalTurn(
        int $conversationId,
        ChatOwner $owner,
        QueryException $contention,
    ): AgentTurnClaim {
        return DB::transaction(function () use ($conversationId, $owner, $contention): AgentTurnClaim {
            $conversation = $this->lockConversation($conversationId, $owner);
            $active = $this->lockActiveTurn($conversation);

            if (! $active instanceof AgentTurn) {
                throw $contention;
            }

            return $this->existingClaim($conversation, $active);
        }, 3);
    }

    private function existingClaim(ChatConversation $conversation, AgentTurn $turn): AgentTurnClaim
    {
        return AgentTurnClaim::existing(
            $turn,
            $this->pendingAgentMessages->existsAfter($conversation, (int) $turn->last_customer_message_id),
        );
    }

    private function lockConversation(int $conversationId, ChatOwner $owner): ChatConversation
    {
        return ChatConversation::query()
            ->forOwner($owner)
            ->whereKey($conversationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockActiveTurn(ChatConversation $conversation): ?AgentTurn
    {
        return AgentTurn::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('status', [AgentTurnStatus::Waiting, AgentTurnStatus::Running])
            ->lockForUpdate()
            ->first();
    }

    private function isTurnContention(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }

        $details = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        return str_contains($details, 'uq_agent_turns_active_conversation')
            || str_contains($details, 'agent_turns.active_conversation_key')
            || str_contains($details, 'uq_agent_turns_message_boundary')
            || (str_contains($details, 'agent_turns.conversation_id')
                && str_contains($details, 'agent_turns.last_customer_message_id'));
    }
}
