<?php

use App\Actions\Chat\CloseChatConversation;
use App\Actions\Chat\ResolveChatOwner;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

test('named active owner contention during creation returns the canonical winner', function () {
    $user = User::factory()->create();
    $winnerPublicId = (string) Str::ulid();
    $insertWinnerAfterOpenLookup = true;

    DB::listen(function (QueryExecuted $query) use (
        &$insertWinnerAfterOpenLookup,
        $user,
        $winnerPublicId,
    ): void {
        if (! $insertWinnerAfterOpenLookup
            || ! str_starts_with(ltrim(strtolower($query->sql)), 'select')
            || ! str_contains($query->sql, 'chat_conversations')) {
            return;
        }

        $insertWinnerAfterOpenLookup = false;
        insertChatContentionWinner($user, $winnerPublicId);
    });

    $response = $this->actingAs($user)->postJson(route('chat.conversations.store'));

    $response->assertOk()->assertJsonPath('data.publicId', $winnerPublicId);
    expect($insertWinnerAfterOpenLookup)->toBeFalse()
        ->and(ChatConversation::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(1)
        ->and(ChatConversation::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('named active owner contention during reopen returns the canonical winner', function () {
    $user = User::factory()->create();
    $inactive = ChatConversation::factory()->forUser($user)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDay(),
    )->create(['last_message_at' => now()->subDay()]);
    $winnerPublicId = (string) Str::ulid();
    $insertWinnerBeforeReopen = true;

    ChatConversation::saving(function (ChatConversation $conversation) use (
        &$insertWinnerBeforeReopen,
        $inactive,
        $user,
        $winnerPublicId,
    ): void {
        if (! $insertWinnerBeforeReopen
            || $conversation->getKey() !== $inactive->getKey()
            || $conversation->status !== ChatConversationStatus::Open) {
            return;
        }

        $insertWinnerBeforeReopen = false;
        insertChatContentionWinner($user, $winnerPublicId);
    });

    $response = $this->actingAs($user)->postJson(route('chat.conversations.store'));

    $response->assertOk()->assertJsonPath('data.publicId', $winnerPublicId);
    expect($insertWinnerBeforeReopen)->toBeFalse()
        ->and($inactive->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($inactive->fresh()->close_reason)->toBe(ChatConversationCloseReason::Inactive)
        ->and(ChatConversation::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(1);
});

test('unrelated query failure during reopen propagates even when an open winner exists', function () {
    $user = User::factory()->create();
    $inactive = ChatConversation::factory()->forUser($user)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDay(),
    )->create(['last_message_at' => now()->subDay()]);
    $insertFailureOnce = true;

    ChatConversation::saving(function (ChatConversation $conversation) use (
        &$insertFailureOnce,
        $inactive,
        $user,
    ): void {
        if (! $insertFailureOnce
            || $conversation->getKey() !== $inactive->getKey()
            || $conversation->status !== ChatConversationStatus::Open) {
            return;
        }

        $insertFailureOnce = false;
        insertChatContentionWinner($user, (string) Str::ulid());
        DB::table('chat_conversations')->insert([
            'public_id' => $inactive->public_id,
            'user_id' => $user->id,
            'guest_key' => null,
            'status' => ChatConversationStatus::Closed->value,
            'locale' => 'ar',
            'last_message_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson(route('chat.conversations.store')))
        ->toThrow(QueryException::class);

    expect($insertFailureOnce)->toBeFalse()
        ->and($inactive->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and(ChatConversation::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(0);
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

test('restart rolls back the close and preserves the pointer when replacement onboarding fails', function () {
    $user = User::factory()->create();
    $current = ChatConversation::factory()->forUser($user)->create();
    $currentPublicId = $current->public_id;

    ChatMessage::creating(static function (): void {
        throw new RuntimeException('Synthetic replacement onboarding failure.');
    });

    $this->actingAs($user)->withSession([
        ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY => $currentPublicId,
    ]);
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson(route('chat.conversations.restart'), ['locale' => 'en']))
        ->toThrow(RuntimeException::class, 'Synthetic replacement onboarding failure.');

    $current->refresh();
    expect($current->status)->toBe(ChatConversationStatus::Open)
        ->and($current->closed_at)->toBeNull()
        ->and($current->close_reason)->toBeNull()
        ->and(ChatConversation::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(session()->get(ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY))->toBe($currentPublicId);
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

function insertChatContentionWinner(User $user, string $publicId): void
{
    DB::table('chat_conversations')->insert([
        'public_id' => $publicId,
        'user_id' => $user->id,
        'guest_key' => null,
        'status' => ChatConversationStatus::Open->value,
        'locale' => 'ar',
        'last_message_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
