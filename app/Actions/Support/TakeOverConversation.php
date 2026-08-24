<?php

namespace App\Actions\Support;

use App\Enums\Chat\ChatHandoffState;
use App\Enums\Chat\ChatSenderType;
use App\Enums\Support\SupportTicketPriority;
use App\Enums\Support\SupportTicketStatus;
use App\Exceptions\Support\TicketAlreadyAssignedException;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\SubjectPreview;
use Illuminate\Support\Facades\DB;

final readonly class TakeOverConversation
{
    public function execute(ChatConversation $conversation, User $staff): SupportTicket
    {
        $conversationId = (int) $conversation->id;

        return DB::transaction(function () use ($conversationId, $staff): SupportTicket {
            // conversation -> ticket -> turn -> run lock order prevents deadlocks against concurrent replies or resolution.
            $lockedConversation = ChatConversation::query()
                ->whereKey($conversationId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var SupportTicket|null $ticket */
            $ticket = SupportTicket::query()
                ->where('conversation_id', $lockedConversation->id)
                ->where('status', SupportTicketStatus::Open)
                ->lockForUpdate()
                ->first();

            if ($ticket instanceof SupportTicket) {
                $isActive = $lockedConversation->handoff_state === ChatHandoffState::Active;
                $isSomeoneElses = $ticket->assigned_admin_id !== null
                    && $ticket->assigned_admin_id !== $staff->id;

                // Only an *active* handoff belongs to another admin (design 4.2).
                // A ticket carrying a merely stale assignee — one left behind by
                // a resolve — is free to claim; refusing it would strand the
                // thread on whoever happened to touch it last.
                if ($isActive && $isSomeoneElses) {
                    throw new TicketAlreadyAssignedException('This ticket is already assigned to another staff member.');
                }

                if ($isActive && $ticket->assigned_admin_id === $staff->id) {
                    return $ticket;
                }

                $ticket->assignedAdmin()->associate($staff);
                $ticket->save();

                $lockedConversation->forceFill([
                    'handoff_state' => ChatHandoffState::Active,
                ])->save();

                return $ticket;
            }

            $firstCustomerMessage = $lockedConversation->messages()
                ->where('sender_type', ChatSenderType::Customer)
                ->orderBy('id')
                ->first();

            $firstContent = $firstCustomerMessage instanceof ChatMessage ? $firstCustomerMessage->content : null;
            // One truncation implementation for the ticket subject and the widget
            // history preview, so a customer is never shown two different
            // summaries of the same thread.
            $subject = SubjectPreview::fromMessage(
                $firstContent,
                $lockedConversation->locale === 'en' ? 'Customer support request' : 'طلب دعم فني',
            );

            $ticket = new SupportTicket([
                'conversation_id' => $lockedConversation->id,
                'user_id' => $lockedConversation->user_id,
                'subject' => $subject,
                'status' => SupportTicketStatus::Open,
                'priority' => SupportTicketPriority::Normal,
            ]);
            $ticket->assignedAdmin()->associate($staff);
            $ticket->save();

            $lockedConversation->forceFill([
                'handoff_state' => ChatHandoffState::Active,
            ])->save();

            return $ticket;
        });
    }
}
