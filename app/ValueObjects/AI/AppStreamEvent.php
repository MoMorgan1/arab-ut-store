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
