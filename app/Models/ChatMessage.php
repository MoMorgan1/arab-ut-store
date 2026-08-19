<?php

namespace App\Models;

use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends DomainModel
{
    /** @var array<string, string> */
    protected $casts = [
        'sender_type' => ChatSenderType::class,
        'message_type' => ChatMessageType::class,
        'metadata' => 'array',
    ];

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }
}
