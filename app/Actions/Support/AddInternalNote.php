<?php

namespace App\Actions\Support;

use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class AddInternalNote
{
    public function execute(
        ChatConversation $conversation,
        User $staff,
        string $content,
    ): ChatMessage {
        $conversationId = (int) $conversation->id;

        return DB::transaction(function () use ($conversationId, $staff, $content): ChatMessage {
            // conversation -> ticket -> turn -> run lock order.
            $lockedConversation = ChatConversation::query()
                ->whereKey($conversationId)
                ->lockForUpdate()
                ->firstOrFail();

            // Notes are internal-only records and intentionally do not touch last_message_at,
            // last_staff_message_at, or handoff_state to avoid disrupting unread indicators or customer state.
            /** @var ChatMessage $message */
            $message = $lockedConversation->messages()->create([
                'sender_type' => ChatSenderType::Staff,
                'staff_user_id' => $staff->id,
                'message_type' => ChatMessageType::InternalNote,
                'content' => $content,
                'reply_to_message_id' => null,
                // Snapshotted because staff_user_id is nullOnDelete: once a
                // staff account is removed the relation is gone, and this is
                // then the only record of who answered the customer.
                'metadata' => ['staffName' => $staff->name],
            ]);

            return $message;
        });
    }
}
