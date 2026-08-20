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
            return DB::transaction(
                fn (): array => $this->createOrRecover($conversation, $content, $clientMessageId),
            );
        } catch (QueryException $exception) {
            return $this->recoverClientIdContention($conversation, $clientMessageId, $exception);
        }
    }

    /** @return array{message: ChatMessage, demoReply: ?ChatMessage} */
    private function createOrRecover(
        ChatConversation $conversation,
        string $content,
        string $clientMessageId,
    ): array {
        $existingMessage = $this->existingMessage($conversation, $clientMessageId);

        if ($existingMessage instanceof ChatMessage) {
            return $this->canonicalMessages($existingMessage);
        }

        $customerMessage = $conversation->messages()->create([
            'client_message_id' => $clientMessageId,
            'sender_type' => ChatSenderType::Customer,
            'message_type' => ChatMessageType::Text,
            'content' => $content,
        ]);

        $conversation->update(['last_message_at' => now()]);
        $demoReply = $this->createDemoReply($conversation, $customerMessage);

        return [
            'message' => $customerMessage,
            'demoReply' => $demoReply,
        ];
    }

    private function createDemoReply(
        ChatConversation $conversation,
        ChatMessage $customerMessage,
    ): ?ChatMessage {
        if (config('chat.demo_assistant', false) !== true) {
            return null;
        }

        return $conversation->messages()->create([
            'reply_to_message_id' => $customerMessage->id,
            'sender_type' => ChatSenderType::Assistant,
            'message_type' => ChatMessageType::Text,
            'content' => $this->demoReplyContent($conversation),
        ]);
    }

    private function demoReplyContent(ChatConversation $conversation): string
    {
        return $conversation->locale === 'en'
            ? 'Got your message 👍 This is the chat foundation demo. Smart replies and tools will be connected in later phases.'
            : 'وصلتني رسالتك 👍 هذي نسخة تجريبية من الشات. قريبًا بنربط الردود الذكية والطلبات.';
    }

    /** @return array{message: ChatMessage, demoReply: ?ChatMessage} */
    private function recoverClientIdContention(
        ChatConversation $conversation,
        string $clientMessageId,
        QueryException $exception,
    ): array {
        if (! $this->isClientIdUniqueViolation($exception)) {
            throw $exception;
        }

        $existingMessage = $this->existingMessage($conversation, $clientMessageId);

        if (! $existingMessage instanceof ChatMessage) {
            throw $exception;
        }

        return $this->canonicalMessages($existingMessage);
    }

    private function existingMessage(
        ChatConversation $conversation,
        string $clientMessageId,
    ): ?ChatMessage {
        return $conversation->messages()
            ->where('client_message_id', $clientMessageId)
            ->first();
    }

    /** @return array{message: ChatMessage, demoReply: ?ChatMessage} */
    private function canonicalMessages(ChatMessage $existingMessage): array
    {
        $existingMessage->load('reply');

        return [
            'message' => $existingMessage,
            'demoReply' => $existingMessage->reply,
        ];
    }

    private function isClientIdUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = $exception->getMessage();

        if ($sqlState !== '23000') {
            return false;
        }

        if ($driverCode === 1062) {
            return str_contains($message, 'uq_chat_messages_client_id');
        }

        return $driverCode === 19
            && str_contains($message, 'UNIQUE constraint failed: chat_messages.conversation_id, chat_messages.client_message_id');
    }
}
