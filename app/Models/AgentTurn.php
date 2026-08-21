<?php

namespace App\Models;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentTurnStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentTurn extends DomainModel
{
    /**
     * The quiet window is sub-second; store this model's dates with milliseconds.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AgentTurnStatus::class,
            'attempt_count' => 'integer',
            'debounce_until' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'terminal_error_code' => AgentErrorCode::class,
        ];
    }

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class);
    }

    /** @return BelongsTo<ChatMessage, $this> */
    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'assistant_message_id');
    }

    /** @return HasMany<AgentRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }
}
