<?php

use App\Actions\Chat\ResolveChatOwner;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

test('guest conversation under old rotated APP_KEY is claimed upon direct user login without preceding chat call', function () {
    $oldAppKey = 'base64:'.base64_encode(str_repeat('1', 32));
    $newAppKey = 'base64:'.base64_encode(str_repeat('2', 32));

    $rawToken = str_repeat('e', 64);
    $oldGuestKey = hash_hmac('sha256', $rawToken, $oldAppKey);
    $newGuestKey = hash_hmac('sha256', $rawToken, $newAppKey);

    // Guest conversation created under old key
    $conversation = ChatConversation::factory()->forGuest($oldGuestKey)->create([
        'locale' => 'ar',
        'last_message_at' => now()->subMinute(),
    ]);
    $guestPublicId = $conversation->public_id;
    $newKeyConversation = ChatConversation::factory()->forGuest($newGuestKey)->create([
        'locale' => 'en',
        'last_message_at' => now(),
    ]);
    $message = ChatMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Guest pre-login message',
    ]);

    // Key is rotated: new key is active, old key is in app.previous_keys
    config()->set('app.key', $newAppKey);
    config()->set('app.previous_keys', [$oldAppKey]);

    $user = User::factory()->create([
        'password' => 'password123',
    ]);

    // User logs in directly via POST /login with the guest session token
    $loginResponse = $this->withSession([
        ResolveChatOwner::SESSION_KEY => $rawToken,
        ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY => $guestPublicId,
    ])
        ->post(route('login'), [
            'email' => $user->email,
            'password' => 'password123',
        ]);

    $loginResponse->assertRedirect();
    $this->assertAuthenticatedAs($user);

    // Verify conversation was claimed to the user
    $conversation->refresh();
    expect($conversation->user_id)->toBe($user->id)
        ->and($conversation->guest_key)->toBeNull()
        ->and($conversation->public_id)->toBe($guestPublicId)
        ->and($conversation->status)->toBe(ChatConversationStatus::Open)
        ->and($conversation->close_reason)->toBeNull()
        ->and($conversation->messages()->count())->toBe(1)
        ->and($conversation->messages()->first()->content)->toBe('Guest pre-login message');

    $newKeyConversation->refresh();
    expect($newKeyConversation->user_id)->toBe($user->id)
        ->and($newKeyConversation->guest_key)->toBeNull()
        ->and($newKeyConversation->status)->toBe(ChatConversationStatus::Closed)
        ->and($newKeyConversation->close_reason)->toBe(ChatConversationCloseReason::SupersededByLoginClaim);

    // Verify guest session token was cleared
    expect(session()->has(ResolveChatOwner::SESSION_KEY))->toBeFalse();
    expect(session()->get(ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY))
        ->toBe($guestPublicId);
});
