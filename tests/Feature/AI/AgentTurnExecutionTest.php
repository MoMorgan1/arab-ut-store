<?php

use App\Actions\AI\StreamAgentTurn;
use App\Contracts\AI\AgentModel;
use App\Contracts\AI\AgentModelResolver;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Enums\AI\AppStreamEventType;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatMessage;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelEvent;
use App\ValueObjects\AI\AgentModelRequest;
use App\ValueObjects\AI\AgentUsage;
use App\ValueObjects\AI\AppStreamEvent;
use App\ValueObjects\Chat\ChatOwner;
use Tests\Support\AI\ScriptedAgentModel;
use Tests\Support\AI\ScriptedAgentModelResolver;

beforeEach(function (): void {
    config()->set('ai-assistant.provider', 'fake');
});

test('a completed stream persists one bounded final message and terminal run', function () {
    $turn = AgentTurn::factory()->create();
    $owner = ChatOwner::guest((string) $turn->conversation->guest_key);
    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver(
        ScriptedAgentModel::completed([
            str_repeat('أ', 2500),
            str_repeat('ب', 2500),
        ]),
    ));

    $events = iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner, 'SAR'));

    $fresh = $turn->fresh();
    $message = ChatMessage::query()->findOrFail($fresh->assistant_message_id);

    expect($fresh->status)->toBe(AgentTurnStatus::Completed)
        ->and($fresh->completed_at)->not->toBeNull()
        ->and($fresh->terminal_error_code)->toBeNull()
        ->and(mb_strlen($message->content))->toBe(4000)
        ->and($message->sender_type)->toBe(ChatSenderType::Assistant)
        ->and($message->message_type)->toBe(ChatMessageType::Text)
        ->and($message->reply_to_message_id)->toBe($fresh->last_customer_message_id)
        ->and(AgentRun::query()->where('agent_turn_id', $turn->id)->count())->toBe(1);

    $run = AgentRun::query()->where('agent_turn_id', $turn->id)->firstOrFail();
    expect($run->status)->toBe(AgentRunStatus::Completed)
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->total_tokens)->toBe(20)
        ->and($run->latency_ms)->toBeGreaterThanOrEqual(0);

    expect($events)->toHaveCount(4)
        ->and($events[0]->type)->toBe(AppStreamEventType::TurnCreated)
        ->and($events[1]->type)->toBe(AppStreamEventType::Delta)
        ->and($events[2]->type)->toBe(AppStreamEventType::Delta)
        ->and($events[3]->type)->toBe(AppStreamEventType::Completed);
});

test('a terminal provider failure fails the turn and run with emitted failure event', function () {
    $turn = AgentTurn::factory()->create();
    $owner = ChatOwner::guest((string) $turn->conversation->guest_key);
    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver(
        ScriptedAgentModel::failures([
            ['code' => AgentErrorCode::ProviderServerError],
        ]),
    ));

    $events = iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner, 'SAR'));

    $fresh = $turn->fresh();
    $run = AgentRun::query()->where('agent_turn_id', $turn->id)->firstOrFail();

    expect($fresh->status)->toBe(AgentTurnStatus::Failed)
        ->and($fresh->terminal_error_code)->toBe(AgentErrorCode::ProviderServerError)
        ->and($fresh->assistant_message_id)->toBeNull()
        ->and($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->error_code)->toBe(AgentErrorCode::ProviderServerError);

    $failedEvent = collect($events)->first(fn (AppStreamEvent $e): bool => $e->type === AppStreamEventType::Failed);
    expect($failedEvent)->not->toBeNull()
        ->and($failedEvent->errorCode)->toBe(AgentErrorCode::ProviderServerError);
});

test('an incomplete provider stream fails with provider_incomplete', function () {
    $turn = AgentTurn::factory()->create();
    $owner = ChatOwner::guest((string) $turn->conversation->guest_key);

    $incompleteModel = new class implements AgentModel
    {
        public function stream(AgentModelRequest $request, AgentDeadline $deadline): Generator
        {
            yield AgentModelEvent::delta('Incomplete start...');
            // Exits without completed or failed
        }
    };
    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver($incompleteModel));

    $events = iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner, 'SAR'));

    $fresh = $turn->fresh();
    $run = AgentRun::query()->where('agent_turn_id', $turn->id)->firstOrFail();

    expect($fresh->status)->toBe(AgentTurnStatus::Failed)
        ->and($fresh->terminal_error_code)->toBe(AgentErrorCode::ProviderIncomplete)
        ->and($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->error_code)->toBe(AgentErrorCode::ProviderIncomplete);

    $lastEvent = end($events);
    expect($lastEvent->type)->toBe(AppStreamEventType::Failed)
        ->and($lastEvent->errorCode)->toBe(AgentErrorCode::ProviderIncomplete);
});

test('run records usage latency and pricing version without sensitive payloads or prompts', function () {
    $turn = AgentTurn::factory()->create();
    $owner = ChatOwner::guest((string) $turn->conversation->guest_key);
    $usage = new AgentUsage(
        inputTokens: 100,
        cachedInputTokens: 20,
        cacheWriteTokens: 10,
        outputTokens: 50,
        reasoningTokens: 15,
        totalTokens: 150,
    );
    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver(
        ScriptedAgentModel::completed(
            deltas: ['Hello customer'],
            usage: $usage,
            providerResponseId: 'resp_real_usage_test',
        ),
    ));

    iterator_to_array(app(StreamAgentTurn::class)->execute($turn, $owner, 'SAR'));

    $run = AgentRun::query()->where('agent_turn_id', $turn->id)->sole();
    expect($run->input_tokens)->toBe(100)
        ->and($run->cached_input_tokens)->toBe(20)
        ->and($run->cache_write_tokens)->toBe(10)
        ->and($run->output_tokens)->toBe(50)
        ->and($run->reasoning_tokens)->toBe(15)
        ->and($run->total_tokens)->toBe(150)
        ->and($run->pricing_version)->toBe('openai-gpt-5.6-luna-2026-08-21')
        ->and($run->provider_response_id)->toBe('resp_real_usage_test-1');
});
