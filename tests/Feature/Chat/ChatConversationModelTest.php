<?php

use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Carbon\Carbon;
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

test('an open conversation cannot be saved with close metadata', function () {
    expect(fn () => ChatConversation::factory()->create([
        'closed_at' => now(),
        'close_reason' => ChatConversationCloseReason::Inactive,
    ]))->toThrow(InvalidArgumentException::class, 'An open conversation cannot have close metadata.');
});

test('a closed conversation factory state supplies typed close metadata', function () {
    $closedAt = Carbon::parse('2026-08-20 10:00:00');

    $conversation = ChatConversation::factory()->closed(
        ChatConversationCloseReason::Inactive,
        $closedAt,
    )->create();

    expect($conversation->status)->toBe(ChatConversationStatus::Closed)
        ->and($conversation->closed_at->equalTo($closedAt))->toBeTrue()
        ->and($conversation->close_reason)->toBe(ChatConversationCloseReason::Inactive);
});

test('conversation lifecycle scopes select open, inactive-closed, and owner rows', function () {
    $open = ChatConversation::factory()->forGuest(str_repeat('a', 64))->create();
    $inactiveClosed = ChatConversation::factory()
        ->forGuest(str_repeat('b', 64))
        ->closed(ChatConversationCloseReason::Inactive, now())
        ->create();
    ChatConversation::factory()
        ->forGuest(str_repeat('c', 64))
        ->closed(ChatConversationCloseReason::CustomerStartedNew, now())
        ->create();

    expect(ChatConversation::query()->open()->pluck('id')->all())->toBe([$open->id])
        ->and(ChatConversation::query()->closedForInactivity()->pluck('id')->all())
        ->toBe([$inactiveClosed->id])
        ->and(ChatConversation::query()->forOwner(ChatOwner::guest(str_repeat('a', 64)))->value('id'))
        ->toBe($open->id);
});

test('message reply relations connect one response to its original message', function () {
    $conversation = ChatConversation::factory()->create();
    $original = ChatMessage::factory()->for($conversation, 'conversation')->create();
    $reply = ChatMessage::factory()->for($conversation, 'conversation')->create([
        'reply_to_message_id' => $original->id,
    ]);

    expect($reply->replyTo->is($original))->toBeTrue()
        ->and($original->reply->is($reply))->toBeTrue();
});
