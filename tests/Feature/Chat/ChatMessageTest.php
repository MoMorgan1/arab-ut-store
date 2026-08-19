<?php

use App\Actions\Chat\ResolveChatOwner;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

test('guest can post message to own conversation and updates last_message_at', function () {
    $rawToken = str_repeat('3', 64);
    $guestKey = hash_hmac('sha256', $rawToken, (string) config('app.key'));
    $oldTime = now()->subMinutes(10);
    $conversation = ChatConversation::factory()->forGuest($guestKey)->create([
        'last_message_at' => $oldTime,
    ]);

    $response = $this->withSession([ResolveChatOwner::SESSION_KEY => $rawToken])
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => '  Hello from guest!  ',
        ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'message' => [
                    'publicId',
                    'conversationPublicId',
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
        ->and($messageData['senderType'])->toBe('customer')
        ->and($messageData['messageType'])->toBe('text')
        ->and($response->json('data.demoReply'))->toBeNull();

    expect($conversation->fresh()->last_message_at->gt($oldTime))->toBeTrue();
});

test('guest cannot post to another guests conversation', function () {
    $ownerToken = str_repeat('4', 64);
    $otherToken = str_repeat('5', 64);
    $ownerGuestKey = hash_hmac('sha256', $ownerToken, (string) config('app.key'));
    $conversation = ChatConversation::factory()->forGuest($ownerGuestKey)->create();

    $response = $this->withSession([ResolveChatOwner::SESSION_KEY => $otherToken])
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => 'Unauthorized message attempt',
        ]);

    $response->assertNotFound()
        ->assertJsonPath('error.code', 'conversation_not_found');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    expect(ChatMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(0);
});

test('empty or oversized content is rejected with validation error', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    // Empty content
    $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => '   ',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['content']);

    // Oversized content (> 4000 characters)
    $oversized = str_repeat('A', 4001);
    $this->actingAs($user)
        ->postJson(route('chat.messages.store', ['conversation' => $conversation->public_id]), [
            'content' => $oversized,
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
        ]);

    $response->assertCreated();
    $data = $response->json('data');

    expect($data['demoReply'])->not->toBeNull()
        ->and($data['demoReply']['senderType'])->toBe('assistant')
        ->and($data['demoReply']['content'])->toContain('وصلتني رسالتك 👍')
        ->and(ChatMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
});
