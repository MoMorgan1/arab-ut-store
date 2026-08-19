<?php

use App\Actions\Chat\ResolveChatOwner;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
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

test('duplicate request with same client_message_id returns existing message idempotently without duplicating DB records', function () {
    config()->set('chat.demo_assistant', true);

    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $clientMessageId = (string) Str::uuid();

    // First send
    $firstResponse = $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => 'عايز كوينز',
            'client_message_id' => $clientMessageId,
        ]);

    $firstResponse->assertCreated();
    $firstMessageId = $firstResponse->json('data.message.publicId');

    // Total messages now = 2 (1 customer + 1 demo reply)
    expect(ChatMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(2);

    // Identical retry with same client_message_id
    $retryResponse = $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => 'عايز كوينز',
            'client_message_id' => $clientMessageId,
        ]);

    $retryResponse->assertCreated();
    expect($retryResponse->json('data.message.publicId'))->toBe($firstMessageId)
        ->and($retryResponse->json('data.demoReply'))->toBeNull()
        // DB count is still exactly 2 (no new customer message, no duplicate canned reply)
        ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
});

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

test('empty, missing client_message_id, or oversized content is rejected with validation error', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    // Empty content
    $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => '   ',
            'client_message_id' => (string) Str::uuid(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['content']);

    // Missing client_message_id
    $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => 'Valid message',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['client_message_id']);

    // Oversized content (> 4000 characters)
    $oversized = str_repeat('A', 4001);
    $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => $oversized,
            'client_message_id' => (string) Str::uuid(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['content']);
});

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
