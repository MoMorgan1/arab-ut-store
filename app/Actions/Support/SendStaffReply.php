<?php

namespace App\Actions\Support;

use App\Enums\Chat\ChatHandoffState;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportReplyNotification;
use Illuminate\Support\Facades\DB;

final readonly class SendStaffReply
{
    public function execute(
        SupportTicket $ticket,
        User $staff,
        string $content,
        ?string $clientMessageId = null,
    ): ChatMessage {
        $conversationId = (int) $ticket->conversation_id;
        $ticketId = (int) $ticket->id;

        return DB::transaction(function () use ($conversationId, $ticketId, $staff, $content, $clientMessageId): ChatMessage {
            // Locking order: conversation -> ticket -> turn -> run.
            $conversation = ChatConversation::query()
                ->whereKey($conversationId)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTicket = SupportTicket::query()
                ->where('conversation_id', $conversation->id)
                ->whereKey($ticketId)
                ->lockForUpdate()
                ->firstOrFail();

            $message = $conversation->messages()->create([
                'sender_type' => ChatSenderType::Staff,
                'staff_user_id' => $staff->id,
                'message_type' => ChatMessageType::Text,
                'content' => $content,
                'client_message_id' => $clientMessageId,
                'reply_to_message_id' => null,
                // Snapshotted because staff_user_id is nullOnDelete: once a
                // staff account is removed the relation is gone, and this is
                // then the only record of who answered the customer.
                'metadata' => ['staffName' => $staff->name],
            ]);

            // last_staff_message_at is what the inbox unread dot and the unread
            // count compare last_message_at against (design 1.1). Without this
            // write every ticket stays permanently unread.
            $now = now();
            $conversation->forceFill([
                'handoff_state' => ChatHandoffState::Active,
                'last_message_at' => $now,
                'last_staff_message_at' => $now,
            ])->save();

            if ($lockedTicket->assigned_admin_id === null) {
                $lockedTicket->assignedAdmin()->associate($staff);
            }

            // Customer away notification check
            $shouldNotify = $this->shouldNotifyCustomer($conversation, $lockedTicket);

            if ($shouldNotify) {
                $lockedTicket->last_notified_at = now();
            }

            $lockedTicket->save();

            if ($shouldNotify) {
                DB::afterCommit(function () use ($lockedTicket, $staff): void {
                    try {
                        $customer = $lockedTicket->user;
                        if ($customer instanceof User && filled($customer->email)) {
                            $customer->notify(new SupportReplyNotification($lockedTicket, $staff));
                        }
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });
            }

            return $message;
        });
    }

    private function shouldNotifyCustomer(ChatConversation $conversation, SupportTicket $ticket): bool
    {
        // At most one email per conversation per hour (design 6.2) — not per
        // ticket. Resolving and reopening mints a fresh ticket with a null
        // last_notified_at, so a per-ticket throttle would let a second full
        // send fire minutes after the first through "Still need help?".
        $lastNotifiedAt = SupportTicket::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('last_notified_at')
            ->orderByDesc('last_notified_at')
            ->first(['id', 'last_notified_at'])?->last_notified_at;

        if ($lastNotifiedAt !== null && $lastNotifiedAt->diffInMinutes(now()) < 60) {
            return false;
        }

        // Inactivity check: the customer has been away for >= 5 minutes.
        //
        // Derived from the messages table rather than a denormalised column on
        // the conversation. last_message_at cannot be used — it moves on staff
        // replies too, so it would report the customer as active at the exact
        // moment a staff reply is written and the email would never fire. A
        // dedicated column would have to be written on every customer message,
        // a hot path, to serve a value read only on the comparatively rare
        // staff reply; this query runs under the conversation lock the caller
        // already holds and hits the (conversation_id, id) index.
        $lastCustomerMessageAt = $conversation->messages()
            ->where('sender_type', ChatSenderType::Customer)
            ->latest('id')
            ->first(['id', 'created_at'])?->created_at;

        if ($lastCustomerMessageAt === null) {
            return false;
        }

        return $lastCustomerMessageAt->diffInMinutes(now()) >= 5;
    }
}
