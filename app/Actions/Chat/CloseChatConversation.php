<?php

namespace App\Actions\Chat;

use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final readonly class CloseChatConversation
{
    public function execute(ChatConversation $conversation, ChatConversationCloseReason $reason): ChatConversation
    {
        return DB::transaction(function () use ($conversation, $reason): ChatConversation {
            $lockedConversation = ChatConversation::query()->lockForUpdate()->findOrFail($conversation->id);

            if ($lockedConversation->status !== ChatConversationStatus::Open) {
                return $lockedConversation;
            }

            $lockedConversation->forceFill([
                'status' => ChatConversationStatus::Closed,
                'closed_at' => now(),
                'close_reason' => $reason,
            ])->save();

            return $lockedConversation;
        });
    }

    public function closeIfInactive(ChatConversation $conversation, DateTimeInterface $cutoff): bool
    {
        return DB::transaction(function () use ($conversation, $cutoff): bool {
            $lockedConversation = ChatConversation::query()
                ->whereKey($conversation->id)
                ->open()
                ->where('last_message_at', '<=', $cutoff)
                ->lockForUpdate()
                ->first();

            if (! $lockedConversation instanceof ChatConversation) {
                return false;
            }

            $lockedConversation->forceFill([
                'status' => ChatConversationStatus::Closed,
                'closed_at' => now(),
                'close_reason' => ChatConversationCloseReason::Inactive,
            ])->save();

            return true;
        });
    }
}
