<?php

namespace App\ValueObjects\AI;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentModelEventType;
use InvalidArgumentException;

final readonly class AgentModelEvent
{
    private function __construct(
        public AgentModelEventType $type,
        public ?string $delta,
        public ?AgentUsage $usage,
        public ?string $providerResponseId,
        public ?AgentErrorCode $errorCode,
        public ?int $retryAfterMilliseconds,
    ) {}

    public static function delta(string $delta): self
    {
        if ($delta === '') {
            throw new InvalidArgumentException('A delta event must contain text.');
        }

        return new self(AgentModelEventType::Delta, $delta, null, null, null, null);
    }

    public static function completed(AgentUsage $usage, ?string $providerResponseId): self
    {
        if ($providerResponseId === '') {
            throw new InvalidArgumentException('A provider response identifier cannot be empty.');
        }

        return new self(AgentModelEventType::Completed, null, $usage, $providerResponseId, null, null);
    }

    public static function failed(AgentErrorCode $errorCode, ?int $retryAfterMilliseconds): self
    {
        if ($retryAfterMilliseconds !== null && $retryAfterMilliseconds < 0) {
            throw new InvalidArgumentException('A retry delay cannot be negative.');
        }

        return new self(AgentModelEventType::Failed, null, null, null, $errorCode, $retryAfterMilliseconds);
    }
}
