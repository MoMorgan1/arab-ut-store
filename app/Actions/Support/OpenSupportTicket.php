<?php

namespace App\Actions\Support;

use App\Enums\Chat\ChatHandoffState;
use App\Enums\Chat\ChatSenderType;
use App\Enums\Support\SupportTicketPriority;
use App\Enums\Support\SupportTicketStatus;
use App\Models\ChatConversation;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class OpenSupportTicket
{
    public function execute(
        ChatConversation $conversation,
        User $customer,
        ?User $staff = null,
        string $openedVia = 'customer_request',
    ): SupportTicket {
        $conversationId = (int) $conversation->id;

        return DB::transaction(function () use ($conversationId, $customer, $staff): SupportTicket {
            // conversation -> ticket -> turn -> run. Never the reverse: a ticket-addressed
            // PATCH that locked the ticket first would deadlock against a concurrent reply.
            $lockedConversation = ChatConversation::query()
                ->whereKey($conversationId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = SupportTicket::query()
                ->where('conversation_id', $lockedConversation->id)
                ->where('status', SupportTicketStatus::Open)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SupportTicket) {
                return $existing;
            }

            $firstCustomerMessage = $lockedConversation->messages()
                ->where('sender_type', ChatSenderType::Customer)
                ->orderBy('id')
                ->first();

            $subject = $this->deriveSubject($firstCustomerMessage?->content, $lockedConversation->locale);

            $ticket = SupportTicket::query()->create([
                'conversation_id' => $lockedConversation->id,
                'user_id' => $customer->id,
                'subject' => $subject,
                'status' => SupportTicketStatus::Open,
                'priority' => SupportTicketPriority::Normal,
                'assigned_admin_id' => $staff?->id,
            ]);

            $lockedConversation->forceFill([
                'handoff_state' => ChatHandoffState::Requested,
            ])->save();

            return $ticket;
        });
    }

    private function deriveSubject(?string $messageContent, ?string $locale): string
    {
        if ($messageContent === null || trim($messageContent) === '') {
            $effectiveLocale = $locale === 'en' ? 'en' : 'ar';

            return $effectiveLocale === 'en'
                ? 'Customer support request'
                : 'طلب دعم فني';
        }

        $trimmed = trim($messageContent);

        if (mb_strlen($trimmed) <= 160) {
            return $trimmed;
        }

        $truncated = mb_substr($trimmed, 0, 160);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return trim($truncated);
    }
}
