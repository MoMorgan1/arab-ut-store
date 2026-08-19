<?php

use App\Actions\Chat\ResolveChatOwner;
use App\Enums\Chat\ChatConversationStatus;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

test('a guest can initialize and fetch an active conversation with seeded onboarding message', function () {
    $response = $this->postJson(route('chat.conversations.store'), [
        'locale' => 'ar',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'publicId',
                'status',
                'locale',
                'subject',
                'lastMessageAt',
                'messages' => [
                    '*' => [
                        'publicId',
                        'conversationPublicId',
                        'senderType',
                        'messageType',
                        'content',
                        'metadata',
                        'createdAt',
                    ],
                ],
                'hasMore',
                'oldestCursor',
            ],
        ]);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $data = $response->json('data');
    expect($data['status'])->toBe('open')
        ->and($data['locale'])->toBe('ar')
        ->and($data['messages'])->toHaveCount(1)
        ->and($data['messages'][0]['senderType'])->toBe('system')
        ->and($data['messages'][0]['messageType'])->toBe('system')
        ->and($data['messages'][0]['content'])->toContain('مساعد عرب التيميت')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $guestToken = session()->get(ResolveChatOwner::SESSION_KEY);
    expect($guestToken)->toBeString()->toHaveLength(64)
        ->and(session()->get(ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY))->toBe($data['publicId']);

    $fetchResponse = $this->getJson(route('chat.conversations.show', ['conversation' => $data['publicId']]));
    $fetchResponse->assertOk()
        ->assertJsonPath('data.publicId', $data['publicId']);
    expect($fetchResponse->headers->get('Cache-Control'))->toContain('no-store');
});

test('guest cannot fetch another guests conversation', function () {
    $otherRawToken = str_repeat('1', 64);
    $otherGuestKey = hash_hmac('sha256', $otherRawToken, (string) config('app.key'));
    $otherConversation = ChatConversation::factory()->forGuest($otherGuestKey)->create();

    $myRawToken = str_repeat('2', 64);
    $response = $this->withSession([ResolveChatOwner::SESSION_KEY => $myRawToken])
        ->getJson(route('chat.conversations.show', ['conversation' => $otherConversation->public_id]));

    $response->assertNotFound()
        ->assertJsonPath('error.code', 'conversation_not_found');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('authenticated user can access own conversation but cannot access another users conversation', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $firstConversation = ChatConversation::factory()->forUser($firstUser)->create();
    $secondConversation = ChatConversation::factory()->forUser($secondUser)->create();

    $this->actingAs($firstUser)
        ->getJson(route('chat.conversations.show', ['conversation' => $firstConversation->public_id]))
        ->assertOk()
        ->assertJsonPath('data.publicId', $firstConversation->public_id);

    $this->actingAs($firstUser)
        ->getJson(route('chat.conversations.show', ['conversation' => $secondConversation->public_id]))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'conversation_not_found');
});

test('multiple conversations preference order: prefers session pointer then latest open conversation', function () {
    $user = User::factory()->create();

    $olderConversation = ChatConversation::factory()->forUser($user)->create([
        'status' => ChatConversationStatus::Open,
        'last_message_at' => now()->subHours(2),
    ]);
    $newerConversation = ChatConversation::factory()->forUser($user)->create([
        'status' => ChatConversationStatus::Open,
        'last_message_at' => now()->subHour(),
    ]);

    // Without session pointer: resolves the latest open conversation
    $response = $this->actingAs($user)->postJson(route('chat.conversations.store'));
    $response->assertOk()
        ->assertJsonPath('data.publicId', $newerConversation->public_id);

    // With explicit session pointer to the older open conversation: prefers the pointed conversation
    $pointerResponse = $this->actingAs($user)
        ->withSession([ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY => $olderConversation->public_id])
        ->postJson(route('chat.conversations.store'));

    $pointerResponse->assertOk()
        ->assertJsonPath('data.publicId', $olderConversation->public_id);
});

test('history is bounded to 50 messages with cursor-based pagination metadata', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    // Create 60 messages
    for ($i = 1; $i <= 60; $i++) {
        ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => ChatSenderType::Customer,
            'message_type' => ChatMessageType::Text,
            'content' => "Message number {$i}",
        ]);
    }

    $response = $this->actingAs($user)->getJson(route('chat.conversations.show', [
        'conversation' => $conversation->public_id,
        'limit' => 50,
    ]));

    $response->assertOk();
    $data = $response->json('data');

    expect($data['messages'])->toHaveCount(50)
        ->and($data['hasMore'])->toBeTrue()
        ->and($data['messages'][0]['content'])->toBe('Message number 11')
        ->and($data['messages'][49]['content'])->toBe('Message number 60')
        ->and($data['oldestCursor'])->toBe($data['messages'][0]['publicId']);

    // Fetch older page before the oldestCursor
    $page2 = $this->actingAs($user)->getJson(route('chat.conversations.show', [
        'conversation' => $conversation->public_id,
        'before_id' => $data['oldestCursor'],
        'limit' => 50,
    ]));

    $page2Data = $page2->json('data');
    expect($page2Data['messages'])->toHaveCount(10)
        ->and($page2Data['hasMore'])->toBeFalse()
        ->and($page2Data['messages'][0]['content'])->toBe('Message number 1')
        ->and($page2Data['messages'][9]['content'])->toBe('Message number 10');
});

test('invalid pagination cursor returns 422 validation response', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    // Invalid format
    $this->actingAs($user)->getJson(route('chat.conversations.show', [
        'conversation' => $conversation->public_id,
        'before_id' => 'invalid-not-ulid',
    ]))->assertStatus(422);

    // Foreign cursor belonging to another conversation
    $otherConversation = ChatConversation::factory()->forUser($user)->create();
    $otherMessage = ChatMessage::factory()->create([
        'conversation_id' => $otherConversation->id,
    ]);

    $response = $this->actingAs($user)->getJson(route('chat.conversations.show', [
        'conversation' => $conversation->public_id,
        'before_id' => $otherMessage->public_id,
    ]));

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_cursor');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('message serialization does not produce N+1 queries', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();

    for ($i = 1; $i <= 30; $i++) {
        ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => ChatSenderType::Customer,
            'message_type' => ChatMessageType::Text,
            'content' => "Query count test {$i}",
        ]);
    }

    DB::enableQueryLog();

    $this->actingAs($user)->getJson(route('chat.conversations.show', [
        'conversation' => $conversation->public_id,
        'limit' => 30,
    ]))->assertOk();

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Query count should be constant (fetching user, conversation, bounded messages, session), NOT 30+ queries
    expect($queryCount)->toBeLessThanOrEqual(6);
});
