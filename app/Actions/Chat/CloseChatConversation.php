<?php

namespace App\Actions\Chat;

use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\DB;

final readonly class CloseChatConversation
{
    public function execute(
        ChatConversation $conversation,
        ChatConversationCloseReason $reason,
    ): ChatConversation {
        return DB::transaction(function () use ($conversation, $reason): ChatConversation {
            $locked = ChatConversation::query()
                ->whereKey($conversation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== ChatConversationStatus::Open) {
                return $locked;
            }

            $locked->forceFill([
                'status' => ChatConversationStatus::Closed,
                'closed_at' => now(),
                'close_reason' => $reason,
            ])->save();

            return $locked;
        });
    }
}
