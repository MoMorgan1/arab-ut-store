<?php

namespace App\Exceptions\Chat;

use RuntimeException;

final class ChatConversationWriteRejected extends RuntimeException
{
    private function __construct(private readonly string $errorCode)
    {
        parent::__construct('The chat conversation no longer accepts this message.');
    }

    public static function notFound(): self
    {
        return new self('conversation_not_found');
    }

    public static function closed(): self
    {
        return new self('conversation_closed');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
