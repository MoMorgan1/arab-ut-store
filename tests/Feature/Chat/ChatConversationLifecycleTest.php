<?php

use App\Actions\Chat\CloseChatConversation;
use App\Actions\Chat\ResolveChatOwner;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

beforeEach(function () {
    config()->set('chat.enabled', true);
    config()->set('chat.reopen_within_days', 7);
});

test('inactive thread reopens within seven days but explicit restart never reopens', function () {
    $user = User::factory()->create();
    $inactive = ChatConversation::factory()->forUser($user)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(2),
    )->create(['last_message_at' => now()->subDays(2)]);

    $this->actingAs($user)->postJson(route('chat.conversations.store'))
        ->assertOk()
        ->assertJsonPath('data.publicId', $inactive->public_id);

    $replacement = $this->actingAs($user)
        ->postJson(route('chat.conversations.restart'), ['locale' => 'ar'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json('data.publicId');

    expect($replacement)->not->toBe($inactive->public_id)
        ->and($inactive->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($inactive->fresh()->close_reason)->toBe(ChatConversationCloseReason::CustomerStartedNew)
        ->and(session()->get(ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY))->toBe($replacement);
});

test('inactive thread older than the reopen window remains closed and a new thread is created', function () {
    $user = User::factory()->create();
    $inactive = ChatConversation::factory()->forUser($user)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(8),
    )->create(['last_message_at' => now()->subDays(8)]);

    $response = $this->actingAs($user)->postJson(route('chat.conversations.store'));

    $response->assertOk();
    expect($response->json('data.publicId'))->not->toBe($inactive->public_id)
        ->and($inactive->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($inactive->fresh()->close_reason)->toBe(ChatConversationCloseReason::Inactive);
});

test('only inactivity closures are eligible for automatic reopen', function (ChatConversationCloseReason $reason) {
    $user = User::factory()->create();
    $closed = ChatConversation::factory()->forUser($user)->closed(
        $reason,
        now()->subDay(),
    )->create(['last_message_at' => now()->subDay()]);

    $response = $this->actingAs($user)->postJson(route('chat.conversations.store'));

    $response->assertOk();
    expect($response->json('data.publicId'))->not->toBe($closed->public_id)
        ->and($closed->fresh()->close_reason)->toBe($reason);
})->with([
    ChatConversationCloseReason::CustomerStartedNew,
    ChatConversationCloseReason::SupersededByLoginClaim,
    ChatConversationCloseReason::InvariantUpgradeDuplicate,
]);

test('conversation and onboarding message creation roll back together', function () {
    ChatMessage::creating(static function (): void {
        throw new RuntimeException('Synthetic onboarding failure.');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson(route('chat.conversations.store'), ['locale' => 'en']))
        ->toThrow(RuntimeException::class, 'Synthetic onboarding failure.');

    expect(ChatConversation::query()->count())->toBe(0)
        ->and(ChatMessage::query()->count())->toBe(0);
});

test('restart is scoped to the resolved owner even when the session points elsewhere', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $current = ChatConversation::factory()->forUser($user)->create();
    $other = ChatConversation::factory()->forUser($otherUser)->create();

    $response = $this->actingAs($user)
        ->withSession([ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY => $other->public_id])
        ->postJson(route('chat.conversations.restart'), ['locale' => 'en', 'limit' => 1]);

    $response->assertOk()
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonCount(1, 'data.messages');

    $replacement = ChatConversation::query()->where('public_id', $response->json('data.publicId'))->sole();

    expect($replacement->user_id)->toBe($user->id)
        ->and($current->fresh()->close_reason)->toBe(ChatConversationCloseReason::CustomerStartedNew)
        ->and($other->fresh()->status)->toBe(ChatConversationStatus::Open)
        ->and($other->fresh()->close_reason)->toBeNull();
});

test('closing an already closed conversation is idempotent', function () {
    $conversation = ChatConversation::factory()->create();
    $action = app(CloseChatConversation::class);

    $first = $action->execute($conversation, ChatConversationCloseReason::CustomerStartedNew);
    $firstClosedAt = $first->closed_at?->copy();
    $this->travel(1)->second();
    $second = $action->execute($conversation, ChatConversationCloseReason::CustomerStartedNew);

    expect($second->status)->toBe(ChatConversationStatus::Closed)
        ->and($second->close_reason)->toBe(ChatConversationCloseReason::CustomerStartedNew)
        ->and($second->closed_at?->equalTo($firstClosedAt))->toBeTrue();
});
