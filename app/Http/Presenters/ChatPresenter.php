<?php

namespace App\Http\Presenters;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Support\Collection;

final class ChatPresenter
{
    /**
     * @param  Collection<int, ChatMessage>  $messages
     * @return array<string, mixed>
     */
    public function conversation(
        ChatConversation $conversation,
        Collection $messages,
        bool $hasMore = false,
        ?string $oldestCursor = null,
    ): array {
        return [
            'publicId' => $conversation->public_id,
            'status' => $conversation->status->value,
            'locale' => $conversation->locale,
            'subject' => $conversation->subject,
            'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
            'messages' => $messages->map(fn (ChatMessage $message) => $this->message($message))->values()->all(),
            'hasMore' => $hasMore,
            'oldestCursor' => $oldestCursor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function message(ChatMessage $message): array
    {
        return [
            'publicId' => $message->public_id,
            'conversationPublicId' => $message->conversation?->public_id,
            'senderType' => $message->sender_type->value,
            'messageType' => $message->message_type->value,
            'content' => $message->content,
            'metadata' => $message->metadata,
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
            ->orderBy('id', 'desc');

        if ($beforePublicId !== null && $beforePublicId !== '') {
            $beforeMessage = ChatMessage::query()
                ->where('conversation_id', $conversation->id)
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
