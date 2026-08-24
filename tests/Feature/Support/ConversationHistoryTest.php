<?php

use App\Actions\Chat\ResolveChatOwner;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SupportTicket;
use App\Models\User;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

it('returns conversations belonging to the authenticated customer only', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $conv1 = ChatConversation::factory()->forUser($user1)->create([
        'subject' => 'Help with FC Coins',
        'last_message_at' => now()->subHour(),
    ]);
    ChatMessage::factory()->for($conv1, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'content' => 'First message for user 1',
    ]);

    $conv2 = ChatConversation::factory()->forUser($user2)->create([
        'subject' => 'User 2 Secret Chat',
        'last_message_at' => now(),
    ]);
    ChatMessage::factory()->for($conv2, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'content' => 'First message for user 2',
    ]);

    $response = $this->actingAs($user1)->getJson(route('chat.conversations.index'));

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'conversations' => [
                    '*' => [
                        'publicId',
                        'subject',
                        'lastMessageAt',
                        'status',
                        'ticketNumber',
                    ],
                ],
                'hasMore',
                'oldestCursor',
            ],
        ]);

    $publicIds = collect($response->json('data.conversations'))->pluck('publicId')->all();
    expect($publicIds)->toContain($conv1->public_id)
        ->and($publicIds)->not->toContain($conv2->public_id);
});

it('returns an empty list immediately for a guest owner', function (): void {
    $rawToken = str_repeat('c', 64);
    $guestKey = hash_hmac('sha256', $rawToken, (string) config('app.key'));
    ChatConversation::factory()->forGuest($guestKey)->create([
        'subject' => 'Guest chat',
    ]);

    $response = $this->withSession([ResolveChatOwner::SESSION_KEY => $rawToken])
        ->getJson(route('chat.conversations.index'));

    $response->assertOk()
        ->assertJson([
            'data' => [
                'conversations' => [],
                'hasMore' => false,
                'oldestCursor' => null,
            ],
        ]);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('includes ticket number and bounds pagination to maximum 10 items', function (): void {
    $user = User::factory()->create();

    $conversations = [];
    for ($i = 0; $i < 12; $i++) {
        // Only the newest thread may be open: the database enforces one open
        // conversation per owner, which is exactly why a history list exists.
        $factory = ChatConversation::factory()->forUser($user);

        if ($i > 0) {
            $factory = $factory->closed(
                ChatConversationCloseReason::CustomerStartedNew,
                now()->subMinutes($i * 5),
            );
        }

        $conv = $factory->create([
            'last_message_at' => now()->subMinutes($i * 5),
        ]);
        ChatMessage::factory()->for($conv, 'conversation')->create([
            'sender_type' => ChatSenderType::Customer,
            'content' => "Message for conversation $i",
        ]);
        if ($i === 0) {
            SupportTicket::factory()->for($conv, 'conversation')->for($user, 'user')->create([
                'ticket_number' => 'TKT-778899',
            ]);
        }
        $conversations[] = $conv;
    }

    $response = $this->actingAs($user)->getJson(route('chat.conversations.index', ['limit' => 10]));

    $response->assertOk();
    $data = $response->json('data.conversations');

    expect($data)->toHaveCount(10)
        ->and($response->json('data.hasMore'))->toBeTrue()
        ->and($data[0]['ticketNumber'])->toBe('TKT-778899');
});

it('never leaks guest_key, user_id, or internal note text in history payload', function (): void {
    $user = User::factory()->create();
    $conv = ChatConversation::factory()->forUser($user)->create();

    ChatMessage::factory()->for($conv, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'content' => 'Customer original request',
    ]);

    ChatMessage::factory()->for($conv, 'conversation')->create([
        'sender_type' => ChatSenderType::Staff,
        'staff_user_id' => $user->id,
        'message_type' => ChatMessageType::InternalNote,
        'content' => 'SECRET_INTERNAL_STAFF_NOTE_DO_NOT_LEAK',
    ]);

    $response = $this->actingAs($user)->getJson(route('chat.conversations.index'));

    $response->assertOk();
    $content = $response->getContent();

    expect($content)->not->toContain('SECRET_INTERNAL_STAFF_NOTE_DO_NOT_LEAK')
        ->and($content)->not->toContain('guest_key')
        ->and($content)->not->toContain('user_id');
});
