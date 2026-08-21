<?php

use App\Actions\AI\PrepareAutomaticAgentRetry;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentTurnStatus;
use App\Http\Presenters\AgentTurnPresenter;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('ineligible owner gets no-store 404 agent_unavailable without resolving turn or provider', function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');
    config()->set('ai-assistant.test_user_ids', [999]);

    $user = User::factory()->create(['id' => 123]);
    $conversation = ChatConversation::factory()->forUser($user)->create();
    ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create([
            'created_at' => now()->subSeconds(2),
        ]);

    $storeResponse = $this->actingAs($user)
        ->postJson(route('chat.agent-turns.store', ['conversation' => $conversation->public_id]));

    $storeResponse->assertStatus(404)
        ->assertJsonPath('error.code', 'agent_unavailable')
        ->assertJsonPath('error.message', trans('chat.unavailable'))
        ->assertJsonPath('error.details', []);
    expect($storeResponse->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and(AgentTurn::query()->count())->toBe(0)
        ->and(AgentRun::query()->count())->toBe(0);

    $turn = AgentTurn::factory()->waiting()->for($conversation, 'conversation')->create();

    $showResponse = $this->actingAs($user)
        ->getJson(route('chat.agent-turns.show', [
            'conversation' => $conversation->public_id,
            'turn' => $turn->public_id,
        ]));

    $showResponse->assertStatus(404)
        ->assertJsonPath('error.code', 'agent_unavailable');
    expect($showResponse->headers->get('Cache-Control'))->toBe('no-store, private');

    $retryResponse = $this->actingAs($user)
        ->postJson(route('chat.agent-turns.retry', [
            'conversation' => $conversation->public_id,
            'turn' => $turn->public_id,
        ]));

    $retryResponse->assertStatus(404)
        ->assertJsonPath('error.code', 'agent_unavailable');
    expect($retryResponse->headers->get('Cache-Control'))->toBe('no-store, private');
});

test('nonquiet request returns bounded 202 without creating a turn', function () {
    Carbon::setTestNow('2026-08-21 12:00:00');
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');
    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create();
    ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create([
            'created_at' => now(),
        ]);

    $response = $this->actingAs($user)
        ->postJson(route('chat.agent-turns.store', ['conversation' => $conversation->public_id]));

    $response->assertAccepted()
        ->assertJsonPath('data.state', 'waiting_for_quiet')
        ->assertJsonPath('data.retryAfterMs', 1500);
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and(AgentTurn::query()->count())->toBe(0);
});

test('idle request with no pending messages returns 204 no content', function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');
    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create();

    $response = $this->actingAs($user)
        ->postJson(route('chat.agent-turns.store', ['conversation' => $conversation->public_id]));

    $response->assertNoContent();
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and(AgentTurn::query()->count())->toBe(0);
});

test('recovered active nonterminal turn returns 202 turn_in_progress with safe state', function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');
    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $turn = AgentTurn::factory()->running()
        ->for($conversation, 'conversation')->create(['attempt_count' => 1]);

    $response = $this->actingAs($user)
        ->postJson(route('chat.agent-turns.store', ['conversation' => $conversation->public_id]));

    $response->assertAccepted()
        ->assertJsonPath('data.state', 'turn_in_progress')
        ->assertJsonPath('data.turn.publicId', $turn->public_id)
        ->assertJsonPath('data.turn.status', 'running');
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private');
});

test('status polling during automatic retry wait stays nonterminal and not retryable', function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');
    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $turn = AgentTurn::factory()->running()
        ->for($conversation, 'conversation')->create(['attempt_count' => 1]);
    $run = AgentRun::factory()->running()->for($turn, 'turn')->create([
        'attempt_number' => 1,
    ]);
    app(PrepareAutomaticAgentRetry::class)->execute($turn, $run);

    $this->actingAs($user)->getJson(route('chat.agent-turns.show', [
        'conversation' => $conversation->public_id,
        'turn' => $turn->public_id,
    ]))->assertOk()
        ->assertJsonPath('data.status', 'waiting')
        ->assertJsonPath('data.retryable', false)
        ->assertJsonPath('data.errorCode', null);
});

test('owner scoping and conversation boundary return 404 for mismatched turn or conversation', function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');

    $userA = User::factory()->create();
    $userB = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$userA->id, $userB->id]);

    $conversationA = ChatConversation::factory()->forUser($userA)->create();
    $conversationB = ChatConversation::factory()->forUser($userB)->create();

    $turnA = AgentTurn::factory()->waiting()->for($conversationA, 'conversation')->create();
    $turnB = AgentTurn::factory()->waiting()->for($conversationB, 'conversation')->create();

    // User A trying to access User B's conversation
    $this->actingAs($userA)->getJson(route('chat.agent-turns.show', [
        'conversation' => $conversationB->public_id,
        'turn' => $turnB->public_id,
    ]))->assertNotFound()
        ->assertJsonPath('error.code', 'conversation_not_found');

    // User A trying to access User B's turn via User A's conversation URL
    $this->actingAs($userA)->getJson(route('chat.agent-turns.show', [
        'conversation' => $conversationA->public_id,
        'turn' => $turnB->public_id,
    ]))->assertNotFound()
        ->assertJsonPath('error.code', 'turn_not_found');

    // User A trying to retry User B's turn via User A's conversation
    $this->actingAs($userA)->postJson(route('chat.agent-turns.retry', [
        'conversation' => $conversationA->public_id,
        'turn' => $turnB->public_id,
    ]))->assertNotFound()
        ->assertJsonPath('error.code', 'turn_not_found');

    // Unknown turn ID
    $this->actingAs($userA)->getJson(route('chat.agent-turns.show', [
        'conversation' => $conversationA->public_id,
        'turn' => '01M00000000000000000000000',
    ]))->assertNotFound()
        ->assertJsonPath('error.code', 'turn_not_found');
});

test('status presentation exposes bounded state and no internal run or provider fields', function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');

    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $turn = AgentTurn::factory()->failed(AgentErrorCode::ProviderTimeout)
        ->for($conversation, 'conversation')->create(['attempt_count' => 2]);
    AgentRun::factory()->for($turn, 'turn')->create([
        'provider' => 'openai',
        'model' => 'gpt-5.6-luna',
        'trace_id' => (string) Str::ulid(),
        'estimated_cost_usd' => '0.00100000',
    ]);

    $response = $this->actingAs($user)->getJson(route('chat.agent-turns.show', [
        'conversation' => $conversation->public_id,
        'turn' => $turn->public_id,
    ]));

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveKeys([
        'publicId', 'status', 'attemptCount', 'retryable',
        'hasPendingMessages', 'errorCode', 'message',
    ])->not->toHaveKeys([
        'provider', 'model', 'traceId', 'tokens', 'latencyMs', 'estimatedCostUsd', 'run', 'runs',
    ]);
});

test('retry is rejected with 409 when turn is not eligible for retry', function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');

    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create();

    // Completed turn cannot be retried
    $completedTurn = AgentTurn::factory()->completed()->for($conversation, 'conversation')->create();
    $this->actingAs($user)->postJson(route('chat.agent-turns.retry', [
        'conversation' => $conversation->public_id,
        'turn' => $completedTurn->public_id,
    ]))->assertStatus(409)
        ->assertJsonPath('error.code', 'turn_not_retryable');

    // Sensitive content blocked turn cannot be retried
    $blockedTurn = AgentTurn::factory()->failed(AgentErrorCode::SensitiveContentBlocked)
        ->for($conversation, 'conversation')->create(['attempt_count' => 1]);
    $this->actingAs($user)->postJson(route('chat.agent-turns.retry', [
        'conversation' => $conversation->public_id,
        'turn' => $blockedTurn->public_id,
    ]))->assertStatus(409)
        ->assertJsonPath('error.code', 'turn_not_retryable');

    // Turn with max attempts (3) cannot be retried
    $maxAttemptsTurn = AgentTurn::factory()->failed(AgentErrorCode::ProviderServerError)
        ->for($conversation, 'conversation')->create(['attempt_count' => 3]);
    $this->actingAs($user)->postJson(route('chat.agent-turns.retry', [
        'conversation' => $conversation->public_id,
        'turn' => $maxAttemptsTurn->public_id,
    ]))->assertStatus(409)
        ->assertJsonPath('error.code', 'turn_not_retryable');
});

test('missing-provider case fails turn safely with configuration_invalid, zero runs, and non-retryable', function () {
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', '');
    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create();
    ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create([
            'created_at' => now()->subSeconds(2),
        ]);

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
        ->toContain('configuration_invalid')
        ->not->toContain('response.completed')
        ->not->toContain('response.delta');

    $turn = AgentTurn::query()->where('conversation_id', $conversation->id)->firstOrFail();
    expect($turn->status)->toBe(AgentTurnStatus::Failed)
        ->and($turn->terminal_error_code)->toBe(AgentErrorCode::ConfigurationInvalid)
        ->and(AgentRun::query()->where('agent_turn_id', $turn->id)->count())->toBe(0)
        ->and(app(AgentTurnPresenter::class)->turn($turn)['retryable'])->toBeFalse()
        ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->where('sender_type', 'assistant')->count())->toBe(0);
});

test('safe 429 rate limiting with nondefault validated limits is observed exactly', function () {
    test('ip rate limiting enforces its own validated ceiling independently', function () {
        config()->set('chat.enabled', true);
        config()->set('ai-assistant.enabled', true);
        config()->set('ai-assistant.rollout', 'authenticated_testers');
        config()->set('ai-assistant.provider', 'fake');
        config()->set('ai-assistant.fake_delta_delay_ms', 0);
        config()->set('ai-assistant.turn_rate_limit_per_minute', 6);
        config()->set('ai-assistant.turn_ip_rate_limit_per_minute', 3);
        $user = User::factory()->create();
        config()->set('ai-assistant.test_user_ids', [$user->id]);
        $conversation = ChatConversation::factory()->forUser($user)->create();
        ChatMessage::factory()->count(6)->customer()->agentEligible()
            ->for($conversation, 'conversation')->create([
                'created_at' => now()->subSeconds(2),
            ]);

        foreach (range(1, 3) as $_) {
            $res = $this->actingAs($user)->postJson(route('chat.agent-turns.store', [
                'conversation' => $conversation->public_id,
            ]));
            if (str_contains((string) $res->headers->get('Content-Type'), 'text/event-stream')) {
                $res->streamedContent();
            }
            expect($res->getStatusCode())->not->toBe(429)
                ->and($res->headers->get('X-RateLimit-Limit'))->toBe('3');
        }

        $blocked = $this->actingAs($user)->postJson(route('chat.agent-turns.store', [
            'conversation' => $conversation->public_id,
        ]));
        $blocked->assertStatus(429);
    });
    config()->set('chat.enabled', true);
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');
    config()->set('ai-assistant.turn_rate_limit_per_minute', 3);
    config()->set('ai-assistant.turn_ip_rate_limit_per_minute', 10);
    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create();
    ChatMessage::factory()->customer()->agentEligible()
        ->for($conversation, 'conversation')->create([
            'created_at' => now()->subSeconds(2),
        ]);

    foreach (['2', '1', '0'] as $expectedRemaining) {
        $res = $this->actingAs($user)->postJson(route('chat.agent-turns.store', [
            'conversation' => $conversation->public_id,
        ]));
        if (str_contains((string) $res->headers->get('Content-Type'), 'text/event-stream')) {
            $res->streamedContent();
        }
        expect($res->getStatusCode())->not->toBe(429)
            ->and($res->headers->get('X-RateLimit-Limit'))->toBe('3')
            ->and($res->headers->get('X-RateLimit-Remaining'))->toBe($expectedRemaining);
    }

    $response = $this->actingAs($user)->postJson(route('chat.agent-turns.store', [
        'conversation' => $conversation->public_id,
    ]));

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.message', trans('chat.rate_limited'))
        ->assertJsonPath('error.details', []);
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('3')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('0');
});
