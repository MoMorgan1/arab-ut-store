<?php

namespace App\Actions\Chat;

use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Database\QueryException;
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
        try {
            return DB::transaction(function () use ($conversation, $content, $clientMessageId): array {
                $existingMessage = $conversation->messages()
                    ->where('client_message_id', $clientMessageId)
                    ->first();

                if ($existingMessage !== null) {
                    $existingMessage->load('reply');

                    return [
                        'message' => $existingMessage,
                        'demoReply' => $existingMessage->reply,
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
                        'reply_to_message_id' => $customerMessage->id,
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
        } catch (QueryException $exception) {
            if (! $this->isClientMessageIdContention($exception)) {
                throw $exception;
            }

            $existingMessage = $conversation->messages()
                ->where('client_message_id', $clientMessageId)
                ->with('reply')
                ->first();

            if (! $existingMessage instanceof ChatMessage) {
                throw $exception;
            }

            return [
                'message' => $existingMessage,
                'demoReply' => $existingMessage->reply,
            ];
        }
    }

    private function isClientMessageIdContention(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }

        $details = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        return str_contains($details, 'uq_chat_messages_client_id')
            || (str_contains($details, 'chat_messages.conversation_id')
                && str_contains($details, 'chat_messages.client_message_id'));
    }
}
