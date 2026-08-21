<?php

namespace App\Actions\AI;

use App\Enums\Chat\ChatSenderType;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;

final readonly class BlockAgentPromptRange
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

            $firstId = (int) $lockedTurn->first_customer_message_id;
            $lastId = (int) $lockedTurn->last_customer_message_id;

            ChatMessage::query()
                ->where('conversation_id', $lockedConversation->id)
                ->where('sender_type', ChatSenderType::Customer)
                ->whereNotNull('agent_eligible_at')
                ->whereNull('agent_prompt_blocked_at')
                ->whereBetween('id', [$firstId, $lastId])
                ->update(['agent_prompt_blocked_at' => now()]);
        }, 3);
    }
}
