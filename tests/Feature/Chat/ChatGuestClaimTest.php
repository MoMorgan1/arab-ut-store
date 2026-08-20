<?php

use App\Actions\Chat\ResolveChatOwner;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
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

test('login preserves the pointed active guest conversation and closes the prior user conversation', function () {
    $user = User::factory()->create();
    $userConversation = ChatConversation::factory()->forUser($user)->create();
    $rawToken = str_repeat('7', 64);
    $guestKey = hash_hmac('sha256', $rawToken, (string) config('app.key'));
    $guestConversation = ChatConversation::factory()->forGuest($guestKey)->create();

    $response = $this->withSession([
        ResolveChatOwner::SESSION_KEY => $rawToken,
        ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY => $guestConversation->public_id,
    ])->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/my-account');
    expect($guestConversation->fresh()->user_id)->toBe($user->id)
        ->and($guestConversation->fresh()->guest_key)->toBeNull()
        ->and($guestConversation->fresh()->status)->toBe(ChatConversationStatus::Open)
        ->and($userConversation->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($userConversation->fresh()->close_reason)->toBe(ChatConversationCloseReason::SupersededByLoginClaim)
        ->and(session()->get(ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY))->toBe($guestConversation->public_id);
});
