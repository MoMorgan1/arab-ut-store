<?php

namespace App\Queries\AI;

use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Builder;

final class PendingAgentMessages
{
    /** @return Builder<ChatMessage> */
    public function query(ChatConversation $conversation, int $afterMessageId): Builder
    {
        return ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', ChatSenderType::Customer)
            ->whereNotNull('agent_eligible_at')
            ->whereNull('agent_prompt_blocked_at')
            ->where('id', '>', $afterMessageId)
            ->whereDoesntHave('reply');
    }

    public function existsAfter(ChatConversation $conversation, int $afterMessageId): bool
    {
        return $this->query($conversation, $afterMessageId)->exists();
    }
}
