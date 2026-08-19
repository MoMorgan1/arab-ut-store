<?php

use App\Actions\Chat\ResolveChatOwner;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

test('guest conversations and message history are claimed into user upon login and guest session token is cleared', function () {
    $user = User::factory()->create();
    $rawToken = str_repeat('6', 64);
    $guestKey = hash_hmac('sha256', $rawToken, (string) config('app.key'));

    $conversation = ChatConversation::factory()->forGuest($guestKey)->create();
    $message = ChatMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_type' => ChatSenderType::Customer,
        'message_type' => ChatMessageType::Text,
        'content' => 'Pre-login guest message',
    ]);

    $response = $this->withSession([
        ResolveChatOwner::SESSION_KEY => $rawToken,
    ])->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/my-account');
    $this->assertAuthenticatedAs($user);
    $response->assertSessionMissing(ResolveChatOwner::SESSION_KEY);

    $claimedConversation = $conversation->fresh();
    expect($claimedConversation->user_id)->toBe($user->id)
        ->and($claimedConversation->guest_key)->toBeNull()
        ->and($claimedConversation->public_id)->toBe($conversation->public_id)
        ->and($message->fresh()->conversation_id)->toBe($claimedConversation->id)
        ->and($message->fresh()->content)->toBe('Pre-login guest message');
});

test('user deletion cascades to delete associated chat conversations and messages', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $message = ChatMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_type' => ChatSenderType::Customer,
        'message_type' => ChatMessageType::Text,
        'content' => 'Cascade test message',
    ]);

    expect(ChatConversation::query()->whereKey($conversation->id)->exists())->toBeTrue()
        ->and(ChatMessage::query()->whereKey($message->id)->exists())->toBeTrue();

    $user->delete();

    expect(ChatConversation::query()->whereKey($conversation->id)->exists())->toBeFalse()
        ->and(ChatMessage::query()->whereKey($message->id)->exists())->toBeFalse();
});
