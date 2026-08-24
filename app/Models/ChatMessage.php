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

    protected static function booted(): void
    {
        static::saving(function (ChatMessage $message): void {
            $isStaff = $message->sender_type === ChatSenderType::Staff;
            $hasStaffUser = $message->staff_user_id !== null;

            if ($isStaff !== $hasStaffUser) {
                throw new \InvalidArgumentException(
                    'A staff chat message must have exactly one staff author, and only a staff message may have one.'
                );
            }

            if ($isStaff && $message->reply_to_message_id !== null) {
                throw new \InvalidArgumentException(
                    'A staff chat message must not claim reply_to_message_id; that column is reserved for agent turns.'
                );
            }
        });
    }

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
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
