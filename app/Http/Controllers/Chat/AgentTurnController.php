<?php

namespace App\Http\Controllers\Chat;

use App\Actions\AI\CreateOrRecoverAgentTurn;
use App\Actions\AI\EnsureAgentTurnTerminal;
use App\Actions\AI\ResolveAssistantMode;
use App\Actions\AI\RetryAgentTurn;
use App\Actions\AI\StreamAgentTurn;
use App\Actions\Chat\ResolveChatOwner;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AppStreamEventType;
use App\Enums\AI\AssistantMode;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AgentTurnPresenter;
use App\Http\Presenters\ChatPresenter;
use App\Http\Responses\ChatErrorResponse;
use App\Http\Responses\SseEventEncoder;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Services\AI\AgentTurnRetryPolicy;
use App\ValueObjects\AI\AppStreamEvent;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentTurnController extends Controller
{
    public function __construct(
        private readonly ResolveChatOwner $resolveChatOwner,
        private readonly ResolveAssistantMode $resolveAssistantMode,
        private readonly CreateOrRecoverAgentTurn $createOrRecoverAgentTurn,
        private readonly RetryAgentTurn $retryAgentTurn,
        private readonly StreamAgentTurn $streamAgentTurn,
        private readonly EnsureAgentTurnTerminal $ensureAgentTurnTerminal,
        private readonly AgentTurnPresenter $agentTurnPresenter,
        private readonly ChatPresenter $chatPresenter,
        private readonly SseEventEncoder $sseEventEncoder,
        private readonly ChatErrorResponse $chatErrorResponse,
        private readonly AgentTurnRetryPolicy $retryPolicy,
    ) {}

    public function store(Request $request, string $conversationPublicId): JsonResponse|Response|StreamedResponse
    {
        $owner = $this->resolveChatOwner->forRequest($request);

        if ($this->resolveAssistantMode->for($owner) !== AssistantMode::Agent) {
            return $this->chatErrorResponse->agentUnavailable();
        }

        $conversation = ChatConversation::query()
            ->forOwner($owner)
            ->where('public_id', $conversationPublicId)
            ->first();

        if (! $conversation instanceof ChatConversation) {
            return $this->conversationNotFoundResponse();
        }

        $claim = $this->createOrRecoverAgentTurn->execute($conversation, $owner);

        if ($claim->shouldStart && $claim->turn instanceof AgentTurn) {
            return $this->streamResponse($claim->turn, $owner);
        }

        if ($claim->retryAfterMilliseconds > 0) {
            return response()->json([
                'data' => [
                    'state' => 'waiting_for_quiet',
                    'retryAfterMs' => $claim->retryAfterMilliseconds,
                ],
            ], 202)->header('Cache-Control', 'no-store, private');
        }

        if ($claim->turn instanceof AgentTurn) {
            return response()->json([
                'data' => [
                    'state' => 'turn_in_progress',
                    'turn' => $this->agentTurnPresenter->turn($claim->turn),
                ],
            ], 202)->header('Cache-Control', 'no-store, private');
        }

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $conversationPublicId, string $turnPublicId): JsonResponse
    {
        $owner = $this->resolveChatOwner->forRequest($request);

        if ($this->resolveAssistantMode->for($owner) !== AssistantMode::Agent) {
            return $this->chatErrorResponse->agentUnavailable();
        }

        $conversation = ChatConversation::query()
            ->forOwner($owner)
            ->where('public_id', $conversationPublicId)
            ->first();

        if (! $conversation instanceof ChatConversation) {
            return $this->conversationNotFoundResponse();
        }

        $turn = AgentTurn::query()
            ->where('conversation_id', $conversation->id)
            ->where('public_id', $turnPublicId)
            ->first();

        if (! $turn instanceof AgentTurn) {
            return $this->turnNotFoundResponse();
        }

        return response()->json([
            'data' => $this->agentTurnPresenter->turn($turn),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function retry(Request $request, string $conversationPublicId, string $turnPublicId): JsonResponse|StreamedResponse
    {
        $owner = $this->resolveChatOwner->forRequest($request);

        if ($this->resolveAssistantMode->for($owner) !== AssistantMode::Agent) {
            return $this->chatErrorResponse->agentUnavailable();
        }

        $conversation = ChatConversation::query()
            ->forOwner($owner)
            ->where('public_id', $conversationPublicId)
            ->first();

        if (! $conversation instanceof ChatConversation) {
            return $this->conversationNotFoundResponse();
        }

        $turn = AgentTurn::query()
            ->where('conversation_id', $conversation->id)
            ->where('public_id', $turnPublicId)
            ->first();

        if (! $turn instanceof AgentTurn) {
            return $this->turnNotFoundResponse();
        }

        if (! $this->retryPolicy->canRetry($turn)) {
            return $this->turnNotRetryableResponse();
        }

        try {
            $retriedTurn = $this->retryAgentTurn->execute($turn);
        } catch (LogicException) {
            return $this->turnNotRetryableResponse();
        }

        return $this->streamResponse($retriedTurn, $owner);
    }

    private function streamResponse(AgentTurn $turn, ChatOwner $owner): StreamedResponse
    {
        return response()->stream(function () use ($turn, $owner): void {
            ignore_user_abort(true);

            try {
                echo $this->sseEventEncoder->heartbeat();
                $this->flush();

                foreach ($this->streamAgentTurn->execute($turn, $owner) as $event) {
                    echo $this->sseEventEncoder->event(
                        $event->type,
                        $this->safeStreamData($event),
                    );
                    $this->flush();
                }
            } finally {
                $this->ensureAgentTurnTerminal->execute($turn);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function safeStreamData(AppStreamEvent $event): array
    {
        return match ($event->type) {
            AppStreamEventType::TurnCreated => [
                'turn' => $event->turn instanceof AgentTurn
                    ? $this->agentTurnPresenter->turn($event->turn)
                    : null,
            ],
            AppStreamEventType::Delta => [
                'turnPublicId' => $event->turnPublicId,
                'delta' => (string) $event->delta,
            ],
            AppStreamEventType::Completed => [
                'turn' => $event->turn instanceof AgentTurn
                    ? $this->agentTurnPresenter->turn($event->turn)
                    : null,
                'message' => $event->message !== null
                    ? $this->chatPresenter->message($event->message, $event->turn?->conversation?->public_id)
                    : null,
            ],
            AppStreamEventType::Failed => [
                'turn' => $event->turn instanceof AgentTurn
                    ? $this->agentTurnPresenter->turn($event->turn)
                    : null,
                'error' => [
                    'code' => ($event->errorCode ?? AgentErrorCode::ProviderTerminalFailure)->value,
                    'message' => trans('chat.'.($event->errorCode ?? AgentErrorCode::ProviderTerminalFailure)->value),
                ],
            ],
        };
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    private function conversationNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'conversation_not_found',
                'message' => 'The requested conversation was not found.',
            ],
        ], 404)->header('Cache-Control', 'no-store, private');
    }

    private function turnNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'turn_not_found',
                'message' => 'The requested agent turn was not found.',
            ],
        ], 404)->header('Cache-Control', 'no-store, private');
    }

    private function turnNotRetryableResponse(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'turn_not_retryable',
                'message' => 'This agent turn cannot be retried.',
            ],
        ], 409)->header('Cache-Control', 'no-store, private');
    }
}
