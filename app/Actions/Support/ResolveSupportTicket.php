<?php

namespace App\Actions\Support;

use App\Enums\Chat\ChatHandoffState;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Enums\Support\SupportTicketStatus;
use App\Models\ChatConversation;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class ResolveSupportTicket
{
    public function execute(SupportTicket $ticket, User $staff): SupportTicket
    {
        $conversationId = (int) $ticket->conversation_id;
        $ticketId = (int) $ticket->id;

        return DB::transaction(function () use ($conversationId, $ticketId, $staff): SupportTicket {
            // conversation -> ticket -> turn -> run.
            $conversation = ChatConversation::query()
                ->whereKey($conversationId)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTicket = SupportTicket::query()
                ->where('conversation_id', $conversation->id)
                ->whereKey($ticketId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTicket->status === SupportTicketStatus::Resolved) {
                return $lockedTicket;
            }

            $lockedTicket->forceFill([
                'status' => SupportTicketStatus::Resolved,
                'resolved_at' => now(),
                'assigned_admin_id' => $lockedTicket->assigned_admin_id ?? $staff->id,
            ])->save();

            $conversation->forceFill([
                'handoff_state' => ChatHandoffState::Resolved,
            ])->save();

            $conversation->messages()->create([
                'sender_type' => ChatSenderType::System,
                'message_type' => ChatMessageType::System,
                // Both translations already exist as chat.assistant_resumed;
                // hardcoding them here put the assistant's old name back into
                // the English copy.
                'content' => (string) trans(
                    'chat.assistant_resumed',
                    locale: $conversation->locale === 'en' ? 'en' : 'ar',
                ),
            ]);

            return $lockedTicket;
        });
    }
}
