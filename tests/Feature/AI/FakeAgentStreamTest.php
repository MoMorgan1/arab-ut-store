<?php

use App\Contracts\AI\AgentModelResolver;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\AI\ScriptedAgentModel;
use Tests\Support\AI\ScriptedAgentModelResolver;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('quiet fake turn streams only app events and persists before completion', function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');
    config()->set('ai-assistant.fake_delta_delay_ms', 0);
    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $customerMessage = ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create([
            'created_at' => now()->subSeconds(2),
        ]);

    $response = $this->actingAs($user)
        ->withHeader('Accept', 'text/event-stream')
        ->post(route('chat.agent-turns.store', [
            'conversation' => $conversation->public_id,
        ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Accel-Buffering', 'no');

    $body = $response->streamedContent();

    expect($body)
        ->toContain(': heartbeat')
        ->toContain('event: turn.created')
        ->toContain('event: response.delta')
        ->toContain('event: response.completed')
        ->not->toContain('response.output_text.delta')
        ->not->toContain('provider_response_id')
        ->not->toContain('estimated_cost_usd')
        ->not->toContain('trace_id');

    $turn = AgentTurn::query()->where('conversation_id', $conversation->id)->firstOrFail();
    expect($turn->status)->toBe(AgentTurnStatus::Completed)
        ->and($turn->completed_at)->not->toBeNull()
        ->and($turn->terminal_error_code)->toBeNull()
        ->and($turn->assistant_message_id)->not->toBeNull();

    $run = AgentRun::query()->where('agent_turn_id', $turn->id)->firstOrFail();
    expect($run->status)->toBe(AgentRunStatus::Completed)
        ->and($run->completed_at)->not->toBeNull();

    $assistantMessage = ChatMessage::query()->findOrFail($turn->assistant_message_id);
    expect($assistantMessage->sender_type)->toBe(ChatSenderType::Assistant)
        ->and($assistantMessage->message_type)->toBe(ChatMessageType::Text)
        ->and($assistantMessage->reply_to_message_id)->toBe($customerMessage->id)
        ->and($assistantMessage->content)->not->toBeEmpty();
});

test('fake stream failure emits response.failed with localized copy and safe code', function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');
    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create();
    ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create([
            'created_at' => now()->subSeconds(2),
        ]);

    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver(
        ScriptedAgentModel::failures([
            ['code' => AgentErrorCode::ProviderServerError],
        ]),
    ));

    $response = $this->actingAs($user)
        ->withHeader('Accept', 'text/event-stream')
        ->post(route('chat.agent-turns.store', [
            'conversation' => $conversation->public_id,
        ]));

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: turn.created')
        ->toContain('event: response.failed')
        ->toContain('provider_server_error')
        ->toContain(trans('chat.provider_server_error'))
        ->not->toContain('response.completed');

    $turn = AgentTurn::query()->where('conversation_id', $conversation->id)->firstOrFail();
    expect($turn->status)->toBe(AgentTurnStatus::Failed)
        ->and($turn->terminal_error_code)->toBe(AgentErrorCode::ProviderServerError);
});

test('stream retry on failed turn executes and completes with persisted message', function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');
    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $turn = AgentTurn::factory()->failed(AgentErrorCode::ProviderServerError)
        ->for($conversation, 'conversation')->create(['attempt_count' => 1]);

    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver(
        ScriptedAgentModel::completed(
            deltas: ['Retried answer from fake agent.'],
            providerResponseId: 'resp_retry_stream_test',
        ),
    ));

    $response = $this->actingAs($user)
        ->withHeader('Accept', 'text/event-stream')
        ->post(route('chat.agent-turns.retry', [
            'conversation' => $conversation->public_id,
            'turn' => $turn->public_id,
        ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

    $body = $response->streamedContent();
    expect($body)
        ->toContain('event: turn.created')
        ->toContain('event: response.delta')
        ->toContain('Retried answer from fake agent.')
        ->toContain('event: response.completed');

    $freshTurn = $turn->fresh();
    expect($freshTurn->status)->toBe(AgentTurnStatus::Completed)
        ->and($freshTurn->assistant_message_id)->not->toBeNull();
});

test('failure event is localized according to chat locale', function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');
    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create(['locale' => 'ar']);
    ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create([
            'created_at' => now()->subSeconds(2),
        ]);

    app()->instance(AgentModelResolver::class, new ScriptedAgentModelResolver(
        ScriptedAgentModel::failures([
            ['code' => AgentErrorCode::ProviderTimeout],
        ]),
    ));

    $response = $this->actingAs($user)
        ->withHeader('Accept', 'text/event-stream')
        ->post(route('chat.agent-turns.store', [
            'conversation' => $conversation->public_id,
        ]));

    $response->assertOk();
    $body = $response->streamedContent();

    expect($body)
        ->toContain('event: response.failed')
        ->toContain('provider_timeout');
});
