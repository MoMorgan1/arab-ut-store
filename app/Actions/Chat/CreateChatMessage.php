<?php

namespace App\Actions\Chat;

use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;

final readonly class CreateChatMessage
{
    /**
     * @return array{message: ChatMessage, demoReply: ?ChatMessage}
     */
    public function execute(
        ChatConversation $conversation,
        string $content,
        string $clientMessageId,
    ): array {
        return DB::transaction(function () use ($conversation, $content, $clientMessageId): array {
            $existingMessage = $conversation->messages()
                ->where('client_message_id', $clientMessageId)
                ->first();

            if ($existingMessage !== null) {
                return [
                    'message' => $existingMessage,
                    'demoReply' => null,
                ];
            }

            $customerMessage = $conversation->messages()->create([
                'client_message_id' => $clientMessageId,
                'sender_type' => ChatSenderType::Customer,
                'message_type' => ChatMessageType::Text,
                'content' => $content,
            ]);

            $conversation->update(['last_message_at' => now()]);

            $demoReply = null;

            if (config('chat.demo_assistant', false) === true) {
                $demoReplyContent = $conversation->locale === 'en'
                    ? 'Got your message 👍 This is the chat foundation demo. Smart replies and tools will be connected in later phases.'
                    : 'وصلتني رسالتك 👍 هذي نسخة تجريبية من الشات. قريبًا بنربط الردود الذكية والطلبات.';

                $demoReply = $conversation->messages()->create([
                    'sender_type' => ChatSenderType::Assistant,
                    'message_type' => ChatMessageType::Text,
                    'content' => $demoReplyContent,
                ]);
            }

            return [
                'message' => $customerMessage,
                'demoReply' => $demoReply,
            ];
        });
    }
}
