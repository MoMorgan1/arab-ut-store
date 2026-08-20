<?php

use App\Actions\Chat\ResolveChatOwner;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('chat.enabled', true);
    config()->set('chat.demo_assistant', false);
});

test('guest can post message to own conversation with client_message_id and updates last_message_at', function () {
    $rawToken = str_repeat('3', 64);
    $guestKey = hash_hmac('sha256', $rawToken, (string) config('app.key'));
    $oldTime = now()->subMinutes(10);
    $conversation = ChatConversation::factory()->forGuest($guestKey)->create([
        'last_message_at' => $oldTime,
    ]);

    $clientMessageId = (string) Str::uuid();

    $response = $this->withSession([ResolveChatOwner::SESSION_KEY => $rawToken])
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => '  Hello from guest!  ',
            'client_message_id' => $clientMessageId,
        ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'message' => [
                    'publicId',
                    'conversationPublicId',
                    'clientMessageId',
                    'senderType',
                    'messageType',
                    'content',
                    'metadata',
                    'createdAt',
                ],
                'demoReply',
            ],
        ]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $messageData = $response->json('data.message');
    expect($messageData['content'])->toBe('Hello from guest!')
        ->and($messageData['clientMessageId'])->toBe($clientMessageId)
        ->and($messageData['senderType'])->toBe('customer')
        ->and($messageData['messageType'])->toBe('text')
        ->and($response->json('data.demoReply'))->toBeNull();

    expect($conversation->fresh()->last_message_at->gt($oldTime))->toBeTrue();
});

test('duplicate request returns the canonical customer and demo reply without changing activity twice', function () {
    config()->set('chat.demo_assistant', true);

    $user = User::factory()->create();
    $firstSendAt = now()->startOfSecond()->addMinute();
    $conversation = ChatConversation::factory()->forUser($user)->create([
        'last_message_at' => $firstSendAt->subHour(),
    ]);
    $clientMessageId = (string) Str::uuid();

    Date::setTestNow($firstSendAt);
    $firstResponse = $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => 'عايز كوينز',
            'client_message_id' => $clientMessageId,
        ]);

    $firstResponse->assertCreated();
    $firstMessageId = $firstResponse->json('data.message.publicId');
    $firstReplyId = $firstResponse->json('data.demoReply.publicId');

    Date::setTestNow($firstSendAt->addMinute());
    $retryResponse = $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => 'عايز كوينز',
            'client_message_id' => $clientMessageId,
        ]);

    $retryResponse->assertCreated();
    expect($retryResponse->json('data.message.publicId'))->toBe($firstMessageId)
        ->and($retryResponse->json('data.demoReply.publicId'))->toBe($firstReplyId)
        ->and($conversation->fresh()->last_message_at->equalTo($firstSendAt))->toBeTrue()
        ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(2)
        ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->where('sender_type', 'customer')->count())->toBe(1)
        ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->where('sender_type', 'assistant')->count())->toBe(1);

    $customerMessage = ChatMessage::query()->where('public_id', $firstMessageId)->sole();
    expect(ChatMessage::query()->where('public_id', $firstReplyId)->sole()->reply_to_message_id)
        ->toBe($customerMessage->id);

    Date::setTestNow();
});

test('owned closed conversation rejects a send before validating its content', function (string $locale, string $message) {
    config()->set('store.default_locale', $locale);

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->closed(
        ChatConversationCloseReason::CustomerStartedNew,
        now(),
    )->create();

    $response = $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), []);

    $response->assertStatus(409)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson([
            'error' => [
                'code' => 'conversation_closed',
                'message' => $message,
                'details' => [],
            ],
        ]);

    expect(json_decode($response->getContent())->error->details)->toEqual((object) [])
        ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(0);
})->with([
    'English' => ['en', 'This conversation is closed. Start a new conversation to continue.'],
    'Arabic' => ['ar', 'المحادثة مقفلة. ابدأ محادثة جديدة للمتابعة.'],
]);

test('arbitrary client metadata is rejected or ignored and not stored', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $clientMessageId = (string) Str::uuid();

    $response = $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => 'Clean message',
            'client_message_id' => $clientMessageId,
            'metadata' => ['injected' => 'malicious_payload', 'role' => 'admin'],
        ]);

    $response->assertCreated();

    $createdMessage = ChatMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('client_message_id', $clientMessageId)
        ->first();

    expect($createdMessage)->not->toBeNull()
        ->and($createdMessage->metadata)->toBeNull();
});

test('guest cannot post to another guests conversation', function () {
    $ownerToken = str_repeat('4', 64);
    $otherToken = str_repeat('5', 64);
    $ownerGuestKey = hash_hmac('sha256', $ownerToken, (string) config('app.key'));
    $conversation = ChatConversation::factory()->forGuest($ownerGuestKey)->create();

    $response = $this->withSession([ResolveChatOwner::SESSION_KEY => $otherToken])
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => 'Unauthorized message attempt',
            'client_message_id' => (string) Str::uuid(),
        ]);

    $response->assertNotFound()
        ->assertJsonPath('error.code', 'conversation_not_found');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    expect(ChatMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(0);
});

test('invalid message input is rejected with the normalized validation error', function (array $payload) {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_error');
})->with([
    'blank content' => [[
        'content' => '   ',
        'client_message_id' => (string) Str::uuid(),
    ]],
    'missing client message ID' => [[
        'content' => 'Valid message',
    ]],
    'oversized content' => [[
        'content' => str_repeat('A', 4001),
        'client_message_id' => (string) Str::uuid(),
    ]],
]);

test('non-blocking demo assistant generates canned reply immediately when enabled', function () {
    config()->set('chat.demo_assistant', true);

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create([
        'locale' => 'ar',
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => 'استفسار عن الكوينز',
            'client_message_id' => (string) Str::uuid(),
        ]);

    $response->assertCreated();
    $data = $response->json('data');

    expect($data['demoReply'])->not->toBeNull()
        ->and($data['demoReply']['senderType'])->toBe('assistant')
        ->and($data['demoReply']['content'])->toContain('وصلتني رسالتك 👍')
        ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
});
