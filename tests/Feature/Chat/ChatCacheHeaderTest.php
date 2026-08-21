<?php

use App\Http\Middleware\SetChatLocale;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

test('200 success responses receive no-store private cache control header', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    $response = $this->actingAs($user)->getJson(route('chat.conversations.show', [
        'conversation' => $conversation->public_id,
    ]));

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('404 not found responses receive no-store private cache control header', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson(route('chat.conversations.show', [
        'conversation' => '01M00000000000000000000000',
    ]));

    $response->assertNotFound();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('422 validation error responses receive no-store private cache control header', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    $response = $this->actingAs($user)->postJson(route('chat.messages.store', [
        'conversation' => $conversation->public_id,
    ]), [
        'content' => '',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('error.message', trans('chat.validation_error'))
        ->assertJsonPath('error.details', []);
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private');
});

test('English chat validation errors use the requested valid locale before throttling', function () {
    $response = $this->postJson(route('chat.conversations.store'), [
        'locale' => 'en',
        'limit' => 0,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('error.message', 'The submitted chat data is invalid.');
});

test('429 throttle responses use the chat rate_limited envelope with private no-store cache control', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create(['locale' => 'en']);

    $rateLimitKey = md5('chat-messages'.'chat-messages:user:'.$user->id);

    foreach (range(1, 30) as $_) {
        RateLimiter::hit($rateLimitKey, 60);
    }

    $response = $this->actingAs($user)->postJson(route('chat.messages.store', [
        'conversation' => $conversation->public_id,
    ]), [
        'content' => 'This request should be throttled.',
        'client_message_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.message', 'Too many chat requests. Please try again shortly.')
        ->assertJsonPath('error.details', []);
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('30')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('0');
});

test('framework 429 errors forward only safe retry guidance', function () {
    $sentinel = 'SENTINEL: never forward this response header';

    Route::post('/chat/testing/rate-limited', static function () use ($sentinel): void {
        throw new TooManyRequestsHttpException(37, headers: [
            'X-RateLimit-Limit' => '30',
            'X-RateLimit-Remaining' => '0',
            'X-Internal-Sentinel' => $sentinel,
        ]);
    })->middleware(SetChatLocale::class);

    $response = $this->postJson('/chat/testing/rate-limited');

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.message', trans('chat.rate_limited'))
        ->assertJsonPath('error.details', []);
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->get('Retry-After'))->toBe('37')
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('30')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('0')
        ->and($response->headers->has('X-Internal-Sentinel'))->toBeFalse();
});

test('unexpected chat failures use a sanitized unavailable envelope with private no-store cache control', function () {
    $sentinel = 'SENTINEL: never expose this database failure';

    Route::post('/chat/testing/unavailable', static function () use ($sentinel): void {
        throw new RuntimeException($sentinel);
    })->middleware(SetChatLocale::class);

    $response = $this->postJson('/chat/testing/unavailable');

    $response->assertStatus(500)
        ->assertJsonPath('error.code', 'chat_unavailable')
        ->assertJsonPath('error.message', trans('chat.unavailable'))
        ->assertJsonPath('error.details', []);
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->getContent())->not->toContain($sentinel);
});

test('framework 409 errors use the sanitized localized conversation_closed envelope', function () {
    $sentinel = 'SENTINEL: never expose this conflict';

    Route::post('/chat/testing/conflict', static function () use ($sentinel): void {
        throw new ConflictHttpException($sentinel);
    })->middleware(SetChatLocale::class);

    $response = $this->postJson('/chat/testing/conflict');

    $response->assertConflict()
        ->assertJsonPath('error.code', 'conversation_closed')
        ->assertJsonPath('error.message', 'المحادثة مقفلة. ابدأ محادثة جديدة للمتابعة.')
        ->assertJsonPath('error.details', []);
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->getContent())->not->toContain($sentinel);
});

test('404 disabled chat responses receive no-store private cache control header', function () {
    config()->set('chat.enabled', false);

    $response = $this->getJson(route('chat.conversations.show', [
        'conversation' => '01M00000000000000000000000',
    ]));

    $response->assertStatus(404);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('agent turn routes return no-store private cache control header across all status codes', function () {
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

    // 200 Stream
    $streamResponse = $this->actingAs($user)
        ->withHeader('Accept', 'text/event-stream')
        ->post(route('chat.agent-turns.store', ['conversation' => $conversation->public_id]));
    $streamResponse->streamedContent();
    $streamResponse->assertOk();
    expect($streamResponse->headers->get('Cache-Control'))->toBe('no-store, private');

    // 204 Idle
    $idleResponse = $this->actingAs($user)
        ->postJson(route('chat.agent-turns.store', ['conversation' => $conversation->public_id]));
    $idleResponse->assertNoContent();
    expect($idleResponse->headers->get('Cache-Control'))->toBe('no-store, private');

    // 200 Show
    $turn = AgentTurn::query()->where('conversation_id', $conversation->id)->firstOrFail();
    $showResponse = $this->actingAs($user)
        ->getJson(route('chat.agent-turns.show', [
            'conversation' => $conversation->public_id,
            'turn' => $turn->public_id,
        ]));
    $showResponse->assertOk();
    expect($showResponse->headers->get('Cache-Control'))->toBe('no-store, private');

    // 409 Retry not allowed on completed turn
    $retryResponse = $this->actingAs($user)
        ->postJson(route('chat.agent-turns.retry', [
            'conversation' => $conversation->public_id,
            'turn' => $turn->public_id,
        ]));
    $retryResponse->assertStatus(409);
    expect($retryResponse->headers->get('Cache-Control'))->toBe('no-store, private');

    // 404 Agent unavailable for non-tester
    $otherUser = User::factory()->create();
    $otherConv = ChatConversation::factory()->forUser($otherUser)->create();
    $unavailResponse = $this->actingAs($otherUser)
        ->postJson(route('chat.agent-turns.store', ['conversation' => $otherConv->public_id]));
    $unavailResponse->assertStatus(404);
    expect($unavailResponse->headers->get('Cache-Control'))->toBe('no-store, private');
});

test('agent-turns rate limiting returns 429 with safe headers and no-store private cache control', function () {
    config()->set('ai-assistant.enabled', true);
    config()->set('ai-assistant.rollout', 'authenticated_testers');
    config()->set('ai-assistant.provider', 'fake');
    config()->set('ai-assistant.turn_rate_limit_per_minute', 2);
    config()->set('ai-assistant.turn_ip_rate_limit_per_minute', 10);

    $user = User::factory()->create();
    config()->set('ai-assistant.test_user_ids', [$user->id]);
    $conversation = ChatConversation::factory()->forUser($user)->create();

    $this->actingAs($user)->postJson(route('chat.agent-turns.store', ['conversation' => $conversation->public_id]));
    $this->actingAs($user)->postJson(route('chat.agent-turns.store', ['conversation' => $conversation->public_id]));

    $response = $this->actingAs($user)->postJson(route('chat.agent-turns.store', [
        'conversation' => $conversation->public_id,
    ]));

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.message', trans('chat.rate_limited'))
        ->assertJsonPath('error.details', []);
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('2')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('0');
});
