<?php

namespace App\Models;

use App\Enums\Chat\ChatConversationStatus;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends DomainModel
{
    /** @var array<string, string> */
    protected $casts = [
        'status' => ChatConversationStatus::class,
        'last_message_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ChatConversation $conversation): void {
            $hasUser = $conversation->user_id !== null;
            $hasGuest = $conversation->guest_key !== null;

            if (($hasUser && $hasGuest) || (! $hasUser && ! $hasGuest)) {
                throw new \InvalidArgumentException('ChatConversation must have exactly one owner: either user_id or guest_key.');
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
}
