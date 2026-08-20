<?php

use App\Enums\Chat\ChatConversationCloseReason;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

test('conversation creation fails if both user_id and guest_key are null', function () {
    expect(function () {
        ChatConversation::query()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => null,
            'guest_key' => null,
            'status' => 'open',
            'locale' => 'ar',
        ]);
    })->toThrow(InvalidArgumentException::class);
});

test('conversation creation fails if both user_id and guest_key are non-null', function () {
    $user = User::factory()->create();

    expect(function () use ($user) {
        ChatConversation::query()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'guest_key' => str_repeat('a', 64),
            'status' => 'open',
            'locale' => 'ar',
        ]);
    })->toThrow(InvalidArgumentException::class);
});

test('conversation creation succeeds for valid user ownership or guest ownership', function () {
    $user = User::factory()->create();

    $userConv = ChatConversation::query()->create([
        'public_id' => (string) Str::ulid(),
        'user_id' => $user->id,
        'guest_key' => null,
        'status' => 'open',
        'locale' => 'ar',
    ]);
    expect($userConv->exists)->toBeTrue();

    $guestConv = ChatConversation::query()->create([
        'public_id' => (string) Str::ulid(),
        'user_id' => null,
        'guest_key' => str_repeat('b', 64),
        'status' => 'open',
        'locale' => 'ar',
    ]);
    expect($guestConv->exists)->toBeTrue();
});

test('an open conversation cannot have close metadata', function () {
    $user = User::factory()->create();

    expect(fn () => ChatConversation::factory()->forUser($user)->create([
        'closed_at' => now(),
        'close_reason' => ChatConversationCloseReason::Inactive,
    ]))->toThrow(InvalidArgumentException::class, 'An open conversation cannot have close metadata.');
});

test('closed factory state and lifecycle scopes expose the intended conversations', function () {
    $user = User::factory()->create();
    $closedAt = Carbon::parse('2026-08-20 12:00:00');
    $open = ChatConversation::factory()->forUser($user)->create();
    $inactive = ChatConversation::factory()->forUser($user)->closed(
        ChatConversationCloseReason::Inactive,
        $closedAt,
    )->create();

    expect(ChatConversation::query()->open()->pluck('id')->all())->toBe([$open->id])
        ->and(ChatConversation::query()->closedForInactivity()->pluck('id')->all())->toBe([$inactive->id])
        ->and($inactive->closed_at->equalTo($closedAt))->toBeTrue()
        ->and($inactive->close_reason)->toBe(ChatConversationCloseReason::Inactive);
});

test('a reply message belongs to its parent and the parent exposes its reply', function () {
    $conversation = ChatConversation::factory()->create();
    $parent = ChatMessage::factory()->create(['conversation_id' => $conversation->id]);
    $reply = ChatMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'reply_to_message_id' => $parent->id,
    ]);

    expect($reply->replyTo->is($parent))->toBeTrue()
        ->and($parent->reply->is($reply))->toBeTrue();
});
