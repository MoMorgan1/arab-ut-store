<?php

namespace App\Http\Presenters;

use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentTurn;
use App\Queries\AI\PendingAgentMessages;
use App\Services\AI\AgentTurnRetryPolicy;

final readonly class AgentTurnPresenter
{
    public function __construct(
        private AgentTurnRetryPolicy $retryPolicy,
        private PendingAgentMessages $pendingAgentMessages,
        private ChatPresenter $chatPresenter,
    ) {}

    /**
     * @return array{
     *     publicId: string,
     *     status: string,
     *     attemptCount: int,
     *     retryable: bool,
     *     hasPendingMessages: bool,
     *     errorCode: string|null,
     *     message: array<string, mixed>|null
     * }
     */
    public function turn(AgentTurn $turn): array
    {
        return [
            'publicId' => $turn->public_id,
            'status' => $turn->status->value,
            'attemptCount' => $turn->attempt_count,
            'retryable' => $this->retryPolicy->canRetry($turn),
            'hasPendingMessages' => in_array($turn->status, [
                AgentTurnStatus::Completed,
                AgentTurnStatus::Failed,
                AgentTurnStatus::Cancelled,
            ], true) && $this->pendingAgentMessages->existsAfter(
                $turn->conversation,
                $turn->last_customer_message_id,
            ),
            'errorCode' => $turn->terminal_error_code?->value,
            'message' => $turn->assistantMessage === null
                ? null
                : $this->chatPresenter->message(
                    $turn->assistantMessage,
                    $turn->conversation->public_id,
                ),
        ];
    }
}
