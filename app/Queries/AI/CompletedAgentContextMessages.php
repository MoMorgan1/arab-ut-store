<?php

namespace App\Queries\AI;

use App\Enums\AI\AgentTurnStatus;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class CompletedAgentContextMessages
{
    /** @return Collection<int, ChatMessage> */
    public function latestBefore(ChatConversation $conversation, int $beforeMessageId, int $limit): Collection
    {
        if ($limit === 0) {
            return new Collection;
        }

        return ChatMessage::query()
            ->where('chat_messages.conversation_id', $conversation->id)
            ->where('chat_messages.id', '<', $beforeMessageId)
            ->where(function (EloquentBuilder $messages): void {
                $messages->where(fn (EloquentBuilder $customer) => $this->completedCustomer($customer))
                    ->orWhere(fn (EloquentBuilder $assistant) => $this->completedAssistant($assistant));
            })
            ->orderByDesc('chat_messages.id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /** @param EloquentBuilder<ChatMessage> $messages */
    private function completedCustomer(EloquentBuilder $messages): void
    {
        $messages->where('chat_messages.sender_type', ChatSenderType::Customer)
            ->whereNotNull('chat_messages.agent_eligible_at')
            ->whereNull('chat_messages.agent_prompt_blocked_at')
            ->whereExists(function (QueryBuilder $turns): void {
                $this->completedTurn($turns)
                    ->whereColumn('chat_messages.id', '>=', 'agent_turns.first_customer_message_id')
                    ->whereColumn('chat_messages.id', '<=', 'agent_turns.last_customer_message_id');
            });
    }

    /** @param EloquentBuilder<ChatMessage> $messages */
    private function completedAssistant(EloquentBuilder $messages): void
    {
        $messages->where('chat_messages.sender_type', ChatSenderType::Assistant)
            ->whereExists(function (QueryBuilder $turns): void {
                $this->completedTurn($turns)
                    ->whereColumn('agent_turns.assistant_message_id', 'chat_messages.id');
            });
    }

    private function completedTurn(QueryBuilder $turns): QueryBuilder
    {
        return $turns->selectRaw('1')
            ->from('agent_turns')
            ->whereColumn('agent_turns.conversation_id', 'chat_messages.conversation_id')
            ->where('agent_turns.status', AgentTurnStatus::Completed->value);
    }
}
