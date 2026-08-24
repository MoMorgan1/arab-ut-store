<?php

use App\Enums\Chat\ChatSenderType;
use App\Enums\UserRole;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Notifications\SupportReplyNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Fortify;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

/**
 * The reply endpoint wraps TakeOverConversation and SendStaffReply — each of
 * which opens its own transaction — in one outer transaction, and the email is
 * dispatched from DB::afterCommit inside the inner one. Every existing test
 * calls the action directly, so nothing covered the nested case: if the
 * callback fired on the inner commit, or never fired at all, the only offline
 * channel in the design would silently stop working in production while the
 * unit test kept passing.
 */
it('still sends the away-customer email when the reply goes through the endpoint', function (): void {
    Notification::fake();

    $customer = User::factory()->create(['email' => 'away@example.com']);

    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'preferred_locale' => 'en',
        'password' => 'SecurePassword!12',
    ]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('STAFFREPLYENDPOINTTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $message = ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'content' => 'Where is my order?',
    ]);
    $message->forceFill([
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(30),
    ])->saveQuietly();

    $this->actingAs($admin)
        ->postJson(route('admin.conversations.reply', ['publicId' => $conversation->public_id]), [
            'content' => 'Checking on it now.',
        ])
        ->assertCreated();

    Notification::assertSentTo($customer, SupportReplyNotification::class);

    // The reply itself must have landed, and taken the thread over with it.
    expect($conversation->fresh()->handoff_state->value)->toBe('active')
        ->and($conversation->fresh()->last_staff_message_at)->not->toBeNull();
});
