<?php

use App\Actions\Chat\CreateChatConversation;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config()->set('chat.enabled', true);
    Carbon::setTestNow('2026-08-20 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('inactive thread reopens at the default seven-day cutoff but explicit restart never reopens', function () {
    $user = User::factory()->create();
    $inactive = ChatConversation::factory()->forUser($user)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(7),
    )->create(['last_message_at' => now()->subDays(7)]);

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

test('inactive thread beyond the default seven-day cutoff is replaced', function () {
    $user = User::factory()->create();
    $inactive = ChatConversation::factory()->forUser($user)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(7)->subSecond(),
    )->create(['last_message_at' => now()->subDays(7)->subSecond()]);

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

    if (in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER chat_messages_fail_onboarding_insert
            BEFORE INSERT ON chat_messages
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Onboarding storage failed.'
            SQL);
    } else {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER chat_messages_fail_onboarding_insert
            BEFORE INSERT ON chat_messages
            BEGIN
                SELECT RAISE(ABORT, 'Onboarding storage failed.');
            END
            SQL);
    }

    try {
        expect(fn () => app(CreateChatConversation::class)->execute(ChatOwner::user($user->id), 'ar'))
            ->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS chat_messages_fail_onboarding_insert');
    }

    expect(ChatConversation::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
