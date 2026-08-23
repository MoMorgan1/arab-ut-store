<?php

namespace App\Models;

use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Enums\Chat\ChatHandoffState;
use App\Enums\Support\SupportTicketStatus;
use App\Support\ChatNumber;
use App\ValueObjects\Chat\ChatOwner;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatConversation extends DomainModel
{
    /** @var array<string, string> */
    protected $casts = [
        'status' => ChatConversationStatus::class,
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
        'close_reason' => ChatConversationCloseReason::class,
        'handoff_state' => ChatHandoffState::class,
        'last_staff_message_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ChatConversation $conversation): void {
            if ($conversation->short_id === null || $conversation->short_id === '') {
                $conversation->short_id = ChatNumber::generate();
            }
        });

        static::saving(function (ChatConversation $conversation): void {
            $hasUser = $conversation->user_id !== null;
            $hasGuest = $conversation->guest_key !== null;

            if (($hasUser && $hasGuest) || (! $hasUser && ! $hasGuest)) {
                throw new \InvalidArgumentException('ChatConversation must have exactly one owner: either user_id or guest_key.');
            }

            if ($conversation->status === ChatConversationStatus::Open
                && ($conversation->closed_at !== null || $conversation->close_reason !== null)) {
                throw new \InvalidArgumentException('An open conversation cannot have close metadata.');
            }
        });
    }

    /** @param Builder<ChatConversation> $query */
    public function scopeForOwner(Builder $query, ChatOwner $owner): void
    {
        if ($owner->userId() !== null) {
            $query->where('user_id', $owner->userId())
                ->whereNull('guest_key');

            return;
        }

        $query->whereNull('user_id')
            ->where('guest_key', $owner->guestKey());
    }

    /** @param Builder<ChatConversation> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ChatConversationStatus::Open);
    }

    /** @param Builder<ChatConversation> $query */
    public function scopeWithLiveHandoff(Builder $query): void
    {
        $query->whereIn('handoff_state', array_map(
            static fn (ChatHandoffState $state): string => $state->value,
            ChatHandoffState::liveStates(),
        ));
    }

    /** @param Builder<ChatConversation> $query */
    public function scopeClosedForInactivity(Builder $query): void
    {
        $query->where('status', ChatConversationStatus::Closed)
            ->where('close_reason', ChatConversationCloseReason::Inactive);
    }

    /** @param Builder<ChatConversation> $query */
    public function scopeWhereLastActivityAtOrAfter(Builder $query, DateTimeInterface $cutoff): void
    {
        $query->whereRaw(
            'COALESCE(last_message_at, closed_at, updated_at) >= ?',
            [$cutoff],
        );
    }

    /** @param Builder<ChatConversation> $query */
    public function scopeWhereLastActivityAtOrBefore(Builder $query, DateTimeInterface $cutoff): void
    {
        $query->whereRaw(
            'COALESCE(last_message_at, closed_at, updated_at) <= ?',
            [$cutoff],
        );
    }

    /** @param Builder<ChatConversation> $query */
    public function scopeOrderByLastActivityDesc(Builder $query): void
    {
        $query->orderByRaw('COALESCE(last_message_at, closed_at, updated_at) DESC');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('id', 'asc');
    }

    /** @return HasMany<AgentTurn, $this> */
    public function agentTurns(): HasMany
    {
        return $this->hasMany(AgentTurn::class, 'conversation_id')->orderBy('id', 'asc');
    }

    /** @return HasMany<SupportTicket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'conversation_id');
    }

    /** @return HasOne<SupportTicket, $this> */
    public function liveTicket(): HasOne
    {
        return $this->hasOne(SupportTicket::class, 'conversation_id')
            ->where('status', SupportTicketStatus::Open);
    }
}
