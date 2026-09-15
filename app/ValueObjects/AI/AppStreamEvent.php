<?php

namespace App\ValueObjects\AI;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AppStreamEventType;
use App\Models\AgentTurn;
use App\Models\ChatMessage;
use InvalidArgumentException;

final readonly class AppStreamEvent
{
    private function __construct(
        public AppStreamEventType $type,
        public string $turnPublicId,
        public ?string $delta = null,
        public ?AgentTurn $turn = null,
        public ?ChatMessage $message = null,
        public ?AgentErrorCode $errorCode = null,
        public ?string $conversationPublicId = null,
        public ?string $subject = null,
    ) {}

    public static function turnCreated(AgentTurn $turn): self
    {
        return new self(
            type: AppStreamEventType::TurnCreated,
            turnPublicId: (string) $turn->public_id,
            turn: $turn,
        );
    }

    public static function delta(string $turnPublicId, string $delta): self
    {
        if ($delta === '') {
            throw new InvalidArgumentException('A delta event cannot be empty.');
        }

        return new self(
            type: AppStreamEventType::Delta,
            turnPublicId: $turnPublicId,
            delta: $delta,
        );
    }

    public static function completed(AgentTurn $turn, ChatMessage $message): self
    {
        return new self(
            type: AppStreamEventType::Completed,
            turnPublicId: (string) $turn->public_id,
            turn: $turn,
            message: $message,
        );
    }

    /**
     * The title the conversation was just given, sent after the reply has
     * completed so the widget can rename the header without a refetch.
     */
    public static function subject(AgentTurn $turn, string $conversationPublicId, string $subject): self
    {
        if ($subject === '') {
            throw new InvalidArgumentException('A subject event cannot be empty.');
        }

        return new self(
            type: AppStreamEventType::Subject,
            turnPublicId: (string) $turn->public_id,
            conversationPublicId: $conversationPublicId,
            subject: $subject,
        );
    }

    public static function failed(AgentTurn $turn, AgentErrorCode $errorCode): self
    {
        return new self(
            type: AppStreamEventType::Failed,
            turnPublicId: (string) $turn->public_id,
            turn: $turn,
            errorCode: $errorCode,
        );
    }
}
