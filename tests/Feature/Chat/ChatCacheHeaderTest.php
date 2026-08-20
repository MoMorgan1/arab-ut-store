<?php

use App\Http\Middleware\SetChatLocale;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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
    expect($response->headers->get('Cache-Control'))->toBe('no-store, private');
});

test('unexpected chat failures use a sanitized unavailable envelope with private no-store cache control', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $sentinel = 'SENTINEL: never expose this database failure';

    ChatMessage::creating(static function () use ($sentinel): void {
        throw new RuntimeException($sentinel);
    });

    try {
        $response = $this->actingAs($user)->postJson(route('chat.messages.store', [
            'conversation' => $conversation->public_id,
        ]), [
            'content' => 'This request triggers an internal failure.',
            'client_message_id' => (string) Str::uuid(),
        ]);
    } finally {
        ChatMessage::flushEventListeners();
    }

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
