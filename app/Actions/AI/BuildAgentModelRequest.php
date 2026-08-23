<?php

namespace App\Actions\AI;

use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Exceptions\AI\InvalidAgentRequestException;
use App\Exceptions\AI\SensitiveAgentContentException;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Queries\AI\CompletedAgentContextMessages;
use App\Queries\AI\PendingAgentMessages;
use App\Support\AI\AgentRuntimeConfig;
use App\ValueObjects\AI\AgentModelRequest;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;

final readonly class BuildAgentModelRequest
{
    public function __construct(
        private AgentRuntimeConfig $config,
        private PendingAgentMessages $pendingAgentMessages,
        private CompletedAgentContextMessages $completedContextMessages,
        private GuardAgentPromptContent $guardPromptContent,
        private SelectSupportKnowledge $selectKnowledge,
        private BuildLivePriceContext $livePrices,
    ) {}

    public function execute(AgentTurn $turn, ChatOwner $owner): AgentModelRequest
    {
        $conversation = ChatConversation::query()
            ->forOwner($owner)
            ->whereKey($turn->conversation_id)
            ->firstOrFail();
        [$firstMessageId, $lastMessageId] = $this->validatedRange($turn);
        $current = $this->currentMessages($conversation, $firstMessageId, $lastMessageId);
        $prior = $this->completedContextMessages->latestBefore(
            $conversation,
            $firstMessageId,
            $this->config->maxContextMessages() - $current->count(),
        );
        $messages = $this->modelMessages($prior, $current);
        $instructions = File::get(resource_path("ai-assistant/prompts/{$turn->prompt_version}.md"));
        [$knowledge, $topicIds] = $this->knowledgeBlock($current, $conversation->locale);
        $knowledge .= $this->livePriceBlock($topicIds, $conversation->locale);

        return new AgentModelRequest(
            model: $this->config->model(),
            instructions: $instructions."\n\nConversation locale: {$conversation->locale}. Authenticated customer: ".($owner->userId() === null ? 'no' : 'yes').'.'.$knowledge,
            messages: $messages,
            safetyIdentifier: hash_hmac('sha256', $owner->idempotencyScope(), (string) config('app.key')),
            maxOutputTokens: $this->config->maxOutputTokens(),
            reasoningEffort: $this->config->reasoningEffort(),
            locale: $conversation->locale,
        );
    }

    /**
     * Approved store knowledge for the question being asked, injected as a
     * delimited block so the model can quote it and cite the topic it used.
     *
     * @param  Collection<int, ChatMessage>  $current
     * @return array{0: string, 1: list<string>}
     */
    private function knowledgeBlock(Collection $current, string $locale): array
    {
        $limit = $this->config->knowledgeTopicLimit();

        if ($limit === 0) {
            return ['', []];
        }

        $question = $current
            ->filter(fn (ChatMessage $message): bool => $message->sender_type === ChatSenderType::Customer)
            ->pluck('content')
            ->implode(' ');

        $topics = $this->selectKnowledge->execute($question, $limit);

        if ($topics === []) {
            return ['', []];
        }

        $rendered = array_map(
            static fn ($topic): string => "[id: {$topic->id}] {$topic->title($locale)}
{$topic->body($locale)}",
            $topics,
        );

        return [
            '

<store_knowledge>
'.implode('

', $rendered).'
</store_knowledge>',
            array_map(static fn ($topic): string => $topic->id, $topics),
        ];
    }

    /**
     * The price table is only worth its tokens when the customer is asking
     * about something the store actually sells.
     *
     * @param  list<string>  $topicIds
     */
    private function livePriceBlock(array $topicIds, string $locale): string
    {
        $priced = ['coins-service', 'coins-speeds', 'pricing-policy', 'sbc', 'rivals', 'fut-champions'];

        if (array_intersect($topicIds, $priced) === []) {
            return '';
        }

        return $this->livePrices->execute(
            (string) config('store.default_display_currency'),
            $locale,
        );
    }

    /** @return array{int, int} */
    private function validatedRange(AgentTurn $turn): array
    {
        if ($turn->prompt_version !== $this->config->promptVersion()) {
            throw new InvalidAgentRequestException;
        }

        $first = (int) $turn->first_customer_message_id;
        $last = (int) $turn->last_customer_message_id;

        if ($first < 1 || $last < $first) {
            throw new InvalidAgentRequestException;
        }

        return [$first, $last];
    }

    /** @return Collection<int, ChatMessage> */
    private function currentMessages(ChatConversation $conversation, int $first, int $last): Collection
    {
        $messages = $this->pendingAgentMessages->query($conversation, $first - 1)
            ->whereBetween('id', [$first, $last])
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()
            || $messages->count() > $this->config->maxContextMessages()
            || $messages->first()->id !== $first
            || $messages->last()->id !== $last) {
            throw new InvalidAgentRequestException;
        }

        return $messages;
    }

    /**
     * @param  Collection<int, ChatMessage>  $prior
     * @param  Collection<int, ChatMessage>  $current
     * @return list<array{role:string,content:string}>
     */
    private function modelMessages(Collection $prior, Collection $current): array
    {
        $messages = [];

        foreach ($prior as $message) {
            $this->validateMessage($message);

            try {
                $this->guardPromptContent->execute($message->content);
            } catch (SensitiveAgentContentException) {
                continue;
            }

            $messages[] = [
                'role' => $message->sender_type === ChatSenderType::Customer ? 'user' : 'assistant',
                'content' => $message->content,
            ];
        }

        foreach ($current as $message) {
            $this->validateMessage($message);

            $this->guardPromptContent->execute($message->content);

            $messages[] = [
                'role' => $message->sender_type === ChatSenderType::Customer ? 'user' : 'assistant',
                'content' => $message->content,
            ];
        }

        return $messages;
    }

    private function validateMessage(ChatMessage $message): void
    {
        if ($message->message_type !== ChatMessageType::Text
            || ! in_array($message->sender_type, [ChatSenderType::Customer, ChatSenderType::Assistant], true)) {
            throw new InvalidAgentRequestException;
        }
    }
}
