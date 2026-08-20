<?php

use App\Actions\Chat\CreateChatConversation;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

test('inactive thread reopens within seven days but explicit restart never reopens', function () {
    config()->set('chat.reopen_within_days', 7);
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
        ->json('data.publicId');

    expect($replacement)->not->toBe($inactive->public_id)
        ->and($inactive->fresh()->close_reason)
        ->toBe(ChatConversationCloseReason::CustomerStartedNew);
});

test('inactive thread closed more than seven days ago is replaced', function () {
    config()->set('chat.reopen_within_days', 7);
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

test('restart only closes the current owners active conversation', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    $otherConversation = ChatConversation::factory()->forUser($otherUser)->create();

    $response = $this->actingAs($user)->postJson(route('chat.conversations.restart'), ['locale' => 'en']);

    $response->assertOk();
    expect($response->json('data.publicId'))->not->toBe($conversation->public_id)
        ->and($conversation->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($conversation->fresh()->close_reason)->toBe(ChatConversationCloseReason::CustomerStartedNew)
        ->and($otherConversation->fresh()->status)->toBe(ChatConversationStatus::Open)
        ->and($otherConversation->fresh()->close_reason)->toBeNull();
});

test('closed conversations with reasons other than inactivity never reopen', function (ChatConversationCloseReason $reason) {
    config()->set('chat.reopen_within_days', 7);
    $user = User::factory()->create();
    $closed = ChatConversation::factory()->forUser($user)->closed($reason, now()->subDay())->create();

    $response = $this->actingAs($user)->postJson(route('chat.conversations.store'));

    $response->assertOk();
    expect($response->json('data.publicId'))->not->toBe($closed->public_id)
        ->and($closed->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($closed->fresh()->close_reason)->toBe($reason);
})->with([
    ChatConversationCloseReason::CustomerStartedNew,
    ChatConversationCloseReason::SupersededByLoginClaim,
    ChatConversationCloseReason::InvariantUpgradeDuplicate,
]);

test('creating a conversation rolls back when onboarding cannot be saved', function () {
    $user = User::factory()->create();

    ChatMessage::creating(static function (): void {
        throw new RuntimeException('Onboarding storage failed.');
    });

    expect(fn () => app(CreateChatConversation::class)->execute(ChatOwner::user($user->id), 'ar'))
        ->toThrow(RuntimeException::class, 'Onboarding storage failed.')
        ->and(ChatConversation::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
