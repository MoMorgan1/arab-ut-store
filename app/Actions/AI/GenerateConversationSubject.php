<?php

namespace App\Actions\AI;

use App\Contracts\AI\AgentModelResolver;
use App\Contracts\AI\MonotonicClock;
use App\Enums\AI\AgentModelEventType;
use App\Enums\Chat\ChatSenderType;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\AI\EstimateAgentRunCost;
use App\Support\AI\AgentRuntimeConfig;
use App\Support\AI\SubjectText;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelRequest;
use App\ValueObjects\AI\AgentUsage;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Names a conversation from its first exchange.
 *
 * Runs once, after the first assistant reply has been stored and announced,
 * as a second short model call. It never touches the reply and never fails
 * the turn: any problem here leaves the subject empty, and the widget keeps
 * showing the customer's first message instead.
 */
final readonly class GenerateConversationSubject
{
    public function __construct(
        private AgentRuntimeConfig $config,
        private MonotonicClock $clock,
        private AgentModelResolver $agentModelResolver,
        private EstimateAgentRunCost $costEstimator,
    ) {}

    public function execute(AgentTurn $turn, ChatMessage $assistantMessage, ChatOwner $owner): ?string
    {
        if (! $this->config->subjectEnabled()) {
            return null;
        }

        $conversation = ChatConversation::query()
            ->forOwner($owner)
            ->whereKey($turn->conversation_id)
            ->first();

        if (! $conversation instanceof ChatConversation || $conversation->subject !== null) {
            return null;
        }

        $earlierReply = $conversation->messages()
            ->where('sender_type', ChatSenderType::Assistant)
            ->where('id', '<', $assistantMessage->id)
            ->exists();

        if ($earlierReply) {
            return null;
        }

        $customerMessages = $conversation->messages()
            ->where('sender_type', ChatSenderType::Customer)
            ->whereBetween('id', [(int) $turn->first_customer_message_id, (int) $turn->last_customer_message_id])
            ->orderBy('id')
            ->pluck('content');

        if ($customerMessages->isEmpty()) {
            return null;
        }

        $request = new AgentModelRequest(
            model: $this->config->model(),
            instructions: File::get(resource_path("ai-assistant/prompts/{$this->config->subjectPromptVersion()}.md"))
                ."\n\nConversation locale: {$conversation->locale}.",
            messages: [
                ['role' => 'user', 'content' => $customerMessages->implode("\n\n")],
                ['role' => 'assistant', 'content' => $assistantMessage->content],
            ],
            safetyIdentifier: hash_hmac('sha256', $owner->idempotencyScope(), (string) config('app.key')),
            maxOutputTokens: $this->config->subjectMaxOutputTokens(),
            reasoningEffort: $this->config->reasoningEffort(),
            locale: $conversation->locale,
        );
        $deadline = AgentDeadline::afterSeconds($this->clock, $this->config->subjectTimeoutSeconds());
        $model = $this->agentModelResolver->resolve($this->config->provider());
        $text = '';
        $usage = null;

        foreach ($model->stream($request, $deadline) as $event) {
            $deadline->throwIfExpired();

            if ($event->type === AgentModelEventType::Delta) {
                $text .= (string) $event->delta;

                continue;
            }

            if ($event->type !== AgentModelEventType::Completed) {
                return null;
            }

            $usage = $event->usage;

            break;
        }

        $subject = SubjectText::clean($text);

        if ($subject === null) {
            return null;
        }

        // Only the first writer wins; a concurrent retry stream that raced
        // this one leaves the row alone and reports the title that stuck.
        ChatConversation::query()
            ->whereKey($conversation->id)
            ->whereNull('subject')
            ->update(['subject' => $subject]);

        if ($usage instanceof AgentUsage) {
            Log::info('chat.subject.generated', [
                'conversation' => $conversation->public_id,
                'total_tokens' => $usage->totalTokens,
                'estimated_cost_usd' => $this->costEstimator->for($usage),
            ]);
        }

        return ChatConversation::query()->whereKey($conversation->id)->value('subject');
    }
}
