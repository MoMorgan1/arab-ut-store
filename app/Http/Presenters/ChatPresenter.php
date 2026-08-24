<?php

namespace App\Http\Presenters;

use App\Enums\AI\AssistantMode;
use App\Enums\Chat\ChatHandoffState;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Enums\Support\SupportTicketStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Collection;

final class ChatPresenter
{
    /**
     * @param  Collection<int, ChatMessage>  $messages
     * @param  array<string, mixed>|null  $latestTurnState
     * @return array<string, mixed>
     */
    public function conversation(
        ChatConversation $conversation,
        Collection $messages,
        AssistantMode $assistantMode,
        ?array $latestTurnState = null,
        bool $hasMore = false,
        ?string $oldestCursor = null,
    ): array {
        $ticket = $this->bannerTicket($conversation);

        return [
            'publicId' => $conversation->public_id,
            'status' => $conversation->status->value,
            'locale' => $conversation->locale,
            'subject' => $conversation->subject,
            'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
            'assistantMode' => $assistantMode->value,
            'handoffState' => $conversation->handoff_state->value,
            'ticket' => $ticket instanceof SupportTicket ? [
                'number' => $ticket->ticket_number,
                'status' => $ticket->status->value,
                'responderName' => $ticket->assignedAdmin?->name,
            ] : null,
            'messages' => $messages->map(fn (ChatMessage $message) => $this->message($message, $conversation->public_id))->values()->all(),
            'latestTurn' => $latestTurnState,
            'hasMore' => $hasMore,
            'oldestCursor' => $oldestCursor,
        ];
    }

    /**
     * The ticket the widget banner describes, in at most one query.
     *
     * A conversation whose handoff_state is `none` has never had a ticket, so
     * there is nothing to look up — and that is the overwhelming majority of
     * conversations, including every page render. Skipping the lookup there is
     * what keeps `chat.conversations.show` and the storefront page render
     * inside their six-query budgets; the naive
     * `liveTicket ?? tickets()->latest()` pair cost one query on every single
     * conversation payload and two whenever no live ticket existed.
     *
     * When a ticket does exist, ordering open-first and newest-second returns
     * the live one if there is one and the most recently closed one otherwise,
     * which is exactly the `liveTicket ?? latest` semantics in a single round
     * trip.
     */
    private function bannerTicket(ChatConversation $conversation): ?SupportTicket
    {
        if ($conversation->handoff_state === ChatHandoffState::None) {
            return null;
        }

        if ($conversation->relationLoaded('liveTicket') && $conversation->liveTicket instanceof SupportTicket) {
            return $conversation->liveTicket;
        }

        return SupportTicket::query()
            ->where('conversation_id', $conversation->id)
            ->with('assignedAdmin:id,first_name,last_name')
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [SupportTicketStatus::Open->value])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function message(ChatMessage $message, ?string $conversationPublicId = null): array
    {
        $staffName = null;

        if ($message->sender_type === ChatSenderType::Staff) {
            $staffName = $message->staffUser?->name;

            // A staff account deleted after the reply nulls staff_user_id, so
            // the name is snapshotted in metadata when one is available.
            if ($staffName === null || $staffName === '') {
                $metadata = $message->metadata;
                $staffName = is_array($metadata) && isset($metadata['staffName'])
                    ? (string) $metadata['staffName']
                    : null;
            }
        }

        return [
            'publicId' => $message->public_id,
            'conversationPublicId' => $conversationPublicId ?? $message->conversation?->public_id,
            'clientMessageId' => $message->client_message_id,
            'senderType' => $message->sender_type->value,
            'messageType' => $message->message_type->value,
            'content' => $message->content,
            'metadata' => $message->metadata,
            'staffName' => $staffName,
            'createdAt' => $message->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }

    /**
     * @return array{messages: Collection<int, ChatMessage>, hasMore: bool, oldestCursor: ?string}
     */
    public function loadBoundedMessages(
        ChatConversation $conversation,
        ?string $beforePublicId = null,
        int $limit = 50,
    ): array {
        $query = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('message_type', '!=', ChatMessageType::InternalNote)
            // Staff bubbles carry a responder name; without this the presenter
            // would lazy-load one user per staff message.
            ->with('staffUser:id,first_name,last_name')
            ->orderBy('id', 'desc');

        if ($beforePublicId !== null && $beforePublicId !== '') {
            $beforeMessage = ChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('message_type', '!=', ChatMessageType::InternalNote)
                ->where('public_id', $beforePublicId)
                ->first();

            if ($beforeMessage instanceof ChatMessage) {
                $query->where('id', '<', $beforeMessage->id);
            }
        }

        $records = $query->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        $items = $records->take($limit)->reverse()->values();
        $oldestCursor = $items->first()?->public_id;

        return [
            'messages' => $items,
            'hasMore' => $hasMore,
            'oldestCursor' => $oldestCursor,
        ];
    }
}
