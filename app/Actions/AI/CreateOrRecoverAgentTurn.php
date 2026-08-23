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

    /**
     * @param  string|null  $displayCurrency  The currency the customer is
     *                                        browsing in, so the reply quotes
     *                                        the same one its cards show.
     */
    public function execute(
        ChatConversation $conversation,
        ChatOwner $owner,
        ?string $displayCurrency = null,
    ): AgentTurnClaim {
        $currency = $this->supportedCurrency($displayCurrency);

        try {
            return DB::transaction(
                fn (): AgentTurnClaim => $this->claimInTransaction((int) $conversation->id, $owner, $currency),
                3,
            );
        } catch (QueryException $exception) {
            if (! $this->isTurnContention($exception)) {
                throw $exception;
            }

            return $this->recoverCanonicalTurn((int) $conversation->id, $owner, $exception);
        }
    }

    private function claimInTransaction(int $conversationId, ChatOwner $owner, ?string $displayCurrency): AgentTurnClaim
    {
        $conversation = $this->lockConversation($conversationId, $owner);
        $active = $this->lockActiveTurn($conversation);

        if ($active instanceof AgentTurn) {
            return $this->existingClaim($conversation, $active);
        }

        return $this->claimPendingRange($conversation, $displayCurrency);
    }

    private function claimPendingRange(ChatConversation $conversation, ?string $displayCurrency): AgentTurnClaim
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

        return $this->createTurn($conversation, $pending, $debounceUntil, $displayCurrency);
    }

    /**
     * Only a currency the store actually displays is recorded. Anything else
     * is dropped so the price table falls back to the store default rather
     * than being built from a value the converter cannot honour.
     */
    private function supportedCurrency(?string $displayCurrency): ?string
    {
        $supported = config('store.display_currencies');

        if (! is_array($supported) || ! in_array($displayCurrency, $supported, true)) {
            return null;
        }

        return $displayCurrency;
    }

    /** @param Builder<ChatMessage> $pending */
    private function createTurn(
        ChatConversation $conversation,
        Builder $pending,
        CarbonInterface $debounceUntil,
        ?string $displayCurrency = null,
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
            'display_currency' => $displayCurrency,
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
