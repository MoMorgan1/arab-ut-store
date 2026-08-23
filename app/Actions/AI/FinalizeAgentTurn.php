<?php

namespace App\Actions\AI;

use App\Enums\AI\AgentModelEventType;
use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\AI\EstimateAgentRunCost;
use App\Support\AI\AgentRuntimeConfig;
use App\ValueObjects\AI\AgentModelEvent;
use App\ValueObjects\AI\AgentUsage;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class FinalizeAgentTurn
{
    public function __construct(
        private AgentRuntimeConfig $config,
        private EstimateAgentRunCost $costEstimator,
        private BuildAssistantCards $buildCards,
    ) {}

    public function execute(
        AgentTurn $turn,
        AgentRun $run,
        string $text,
        AgentModelEvent $providerEvent,
        int $latencyMilliseconds,
    ): ChatMessage {
        return DB::transaction(function () use ($turn, $run, $text, $providerEvent, $latencyMilliseconds): ChatMessage {
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

            if ($lockedTurn->assistant_message_id !== null) {
                return ChatMessage::query()->findOrFail($lockedTurn->assistant_message_id);
            }

            if ($providerEvent->type !== AgentModelEventType::Completed) {
                throw new LogicException('Finalizing an agent turn requires a completed provider event.');
            }

            $trimmedText = mb_substr(
                $text,
                0,
                $this->config->maxResponseCharacters(),
            );

            if (trim($trimmedText) === '') {
                throw new LogicException('An assistant message cannot be empty.');
            }

            $usage = $providerEvent->usage;

            if (! $usage instanceof AgentUsage) {
                throw new LogicException('A completed provider event requires usage.');
            }

            $cards = $this->buildCards->execute(
                $this->customerText($lockedTurn),
                (string) $lockedConversation->locale,
            );

            $assistantMessage = $lockedConversation->messages()->create([
                'reply_to_message_id' => $lockedTurn->last_customer_message_id,
                'sender_type' => ChatSenderType::Assistant,
                'message_type' => ChatMessageType::Text,
                'content' => $trimmedText,
                'metadata' => $cards === []
                    ? null
                    : ['cards' => ['version' => 'cards.v1', 'items' => $cards]],
            ]);

            $lockedRun->forceFill([
                'provider_response_id' => $providerEvent->providerResponseId,
                'status' => AgentRunStatus::Completed,
                'latency_ms' => $latencyMilliseconds,
                'input_tokens' => $usage->inputTokens,
                'cached_input_tokens' => $usage->cachedInputTokens,
                'cache_write_tokens' => $usage->cacheWriteTokens,
                'output_tokens' => $usage->outputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
                'total_tokens' => $usage->totalTokens,
                'estimated_cost_usd' => $this->costEstimator->for($usage),
                'completed_at' => now(),
            ])->save();

            $lockedTurn->forceFill([
                'assistant_message_id' => $assistantMessage->id,
                'status' => AgentTurnStatus::Completed,
                'completed_at' => now(),
                'terminal_error_code' => null,
            ])->save();

            $lockedConversation->forceFill(['last_message_at' => now()])->save();

            return $assistantMessage;
        }, 3);
    }

    /** The customer messages this turn answered, used to derive service cards. */
    private function customerText(AgentTurn $turn): string
    {
        return ChatMessage::query()
            ->where('conversation_id', $turn->conversation_id)
            ->where('sender_type', ChatSenderType::Customer)
            ->whereBetween('id', [
                (int) $turn->first_customer_message_id,
                (int) $turn->last_customer_message_id,
            ])
            ->orderBy('id')
            ->pluck('content')
            ->implode(' ');
    }
}
