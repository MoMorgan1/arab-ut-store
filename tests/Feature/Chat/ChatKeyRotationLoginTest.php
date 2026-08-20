<?php

use App\Actions\Chat\ResolveChatOwner;
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

    // Guest conversation created under old key
    $conversation = ChatConversation::factory()->forGuest($oldGuestKey)->create([
        'locale' => 'ar',
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
        ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY => $conversation->public_id,
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
        ->and($conversation->public_id)->toBe($conversation->public_id)
        ->and($conversation->messages()->count())->toBe(1)
        ->and($conversation->messages()->first()->content)->toBe('Guest pre-login message');

    // Verify guest session token was cleared
    expect(session()->has(ResolveChatOwner::SESSION_KEY))->toBeFalse();
    expect(session()->get(ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY))->toBe($conversation->public_id);
});
