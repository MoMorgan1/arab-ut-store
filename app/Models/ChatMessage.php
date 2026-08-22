<?php

namespace App\Models;

use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatMessage extends DomainModel
{
    /** @var array<string, string> */
    protected $casts = [
        'sender_type' => ChatSenderType::class,
        'message_type' => ChatMessageType::class,
        'metadata' => 'array',
        'agent_eligible_at' => 'datetime',
        'agent_prompt_blocked_at' => 'datetime',
    ];

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<ChatMessage, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    /** @return HasOne<ChatMessage, $this> */
    public function reply(): HasOne
    {
        return $this->hasOne(self::class, 'reply_to_message_id');
    }

    /** @return HasOne<AgentTurn, $this> */
    public function completedAgentTurn(): HasOne
    {
        return $this->hasOne(AgentTurn::class, 'assistant_message_id');
    }
}
