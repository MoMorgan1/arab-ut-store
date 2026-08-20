<?php

use App\Actions\Chat\CloseChatConversation;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('default maintenance cutoffs close inactive conversations and purge expired owner-specific conversations', function () {
    Carbon::setTestNow('2026-08-20 12:00:00');

    $recentOpenGuestKey = hash('sha256', 'recent-open-guest');
    $inactiveOpenGuestKey = hash('sha256', 'inactive-open-guest');
    $retainedGuestKey = hash('sha256', 'retained-guest');
    $expiredGuestKey = hash('sha256', 'expired-guest');
    $retainedGuestMessage = 'retained guest message content';
    $expiredGuestMessage = 'expired guest message content';

    $recentOpen = ChatConversation::factory()->forGuest($recentOpenGuestKey)->create([
        'last_message_at' => now()->subHours(23),
    ]);
    $inactiveOpen = ChatConversation::factory()->forGuest($inactiveOpenGuestKey)->create([
        'last_message_at' => now()->subHours(24),
    ]);
    $retainedGuest = ChatConversation::factory()->forGuest($retainedGuestKey)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(29),
    )->create(['last_message_at' => now()->subDays(29)]);
    $expiredGuest = ChatConversation::factory()->forGuest($expiredGuestKey)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(30),
    )->create(['last_message_at' => now()->subDays(30)]);

    $user = User::factory()->create();
    $retainedUser = ChatConversation::factory()->forUser($user)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(179),
    )->create(['last_message_at' => now()->subDays(179)]);
    $expiredUser = ChatConversation::factory()->forUser(User::factory()->create())->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(180),
    )->create(['last_message_at' => now()->subDays(180)]);

    $retainedGuestMessageRecord = ChatMessage::factory()->create([
        'conversation_id' => $retainedGuest->id,
        'content' => $retainedGuestMessage,
    ]);
    $expiredGuestMessageRecord = ChatMessage::factory()->create([
        'conversation_id' => $expiredGuest->id,
        'content' => $expiredGuestMessage,
    ]);
    $expiredUserMessageRecord = ChatMessage::factory()->create([
        'conversation_id' => $expiredUser->id,
    ]);

    $this->artisan('chat:maintain-conversations')
        ->expectsOutputToContain('Closed 1 inactive conversation(s).')
        ->expectsOutputToContain('Deleted 2 expired conversation(s).')
        ->doesntExpectOutputToContain($expiredGuestKey)
        ->doesntExpectOutputToContain($expiredGuestMessage)
        ->assertSuccessful();

    expect($recentOpen->fresh()->status)->toBe(ChatConversationStatus::Open)
        ->and($inactiveOpen->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($inactiveOpen->fresh()->close_reason)->toBe(ChatConversationCloseReason::Inactive)
        ->and($retainedGuest->fresh())->not->toBeNull()
        ->and($retainedUser->fresh())->not->toBeNull()
        ->and($expiredGuest->fresh())->toBeNull()
        ->and($expiredUser->fresh())->toBeNull()
        ->and($retainedGuestMessageRecord->fresh())->not->toBeNull()
        ->and($expiredGuestMessageRecord->fresh())->toBeNull()
        ->and($expiredUserMessageRecord->fresh())->toBeNull();

    $this->artisan('chat:maintain-conversations')
        ->expectsOutputToContain('Closed 0 inactive conversation(s).')
        ->expectsOutputToContain('Deleted 0 expired conversation(s).')
        ->assertSuccessful();
});

test('inactive maintenance ignores a candidate refreshed before its locked close', function () {
    Carbon::setTestNow('2026-08-20 12:00:00');

    $conversation = ChatConversation::factory()->create([
        'last_message_at' => now()->subHours(24),
    ]);
    $staleCandidate = $conversation->fresh();
    $conversation->update(['last_message_at' => now()]);

    $closed = app(CloseChatConversation::class)->closeIfInactive(
        $staleCandidate,
        now()->subHours(24),
    );

    expect($closed)->toBeFalse()
        ->and($conversation->fresh()->status)->toBe(ChatConversationStatus::Open)
        ->and($conversation->fresh()->last_message_at->equalTo(now()))->toBeTrue();
});

test('retention follows last activity with closed_at then updated_at as legacy null fallbacks', function () {
    Carbon::setTestNow('2026-08-20 12:00:00');

    $expiredGuest = ChatConversation::factory()->forGuest(hash('sha256', 'activity-expired-guest'))->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDay(),
    )->create(['last_message_at' => now()->subDays(30)]);
    $retainedGuest = ChatConversation::factory()->forGuest(hash('sha256', 'activity-retained-guest'))->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(40),
    )->create(['last_message_at' => now()->subDays(30)->addSecond()]);

    $expiredUser = ChatConversation::factory()->forUser(User::factory()->create())->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDay(),
    )->create(['last_message_at' => now()->subDays(180)]);
    $retainedUser = ChatConversation::factory()->forUser(User::factory()->create())->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(190),
    )->create(['last_message_at' => now()->subDays(180)->addSecond()]);

    $closedAtFallback = ChatConversation::factory()->forGuest(hash('sha256', 'closed-at-fallback'))->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(30),
    )->create(['last_message_at' => null]);
    DB::table('chat_conversations')->where('id', $closedAtFallback->id)->update([
        'last_message_at' => null,
        'closed_at' => now()->subDays(30),
        'updated_at' => now(),
    ]);

    $updatedAtFallback = ChatConversation::factory()->forUser(User::factory()->create())->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDay(),
    )->create(['last_message_at' => null]);
    DB::table('chat_conversations')->where('id', $updatedAtFallback->id)->update([
        'last_message_at' => null,
        'closed_at' => null,
        'updated_at' => now()->subDays(180),
    ]);

    $this->artisan('chat:maintain-conversations')
        ->expectsOutputToContain('Deleted 4 expired conversation(s).')
        ->assertSuccessful();

    expect($expiredGuest->fresh())->toBeNull()
        ->and($retainedGuest->fresh())->not->toBeNull()
        ->and($expiredUser->fresh())->toBeNull()
        ->and($retainedUser->fresh())->not->toBeNull()
        ->and($closedAtFallback->fresh())->toBeNull()
        ->and($updatedAtFallback->fresh())->toBeNull();
});

test('inactive maintenance preserves a stale candidate closed for a protected reason', function (ChatConversationCloseReason $reason) {
    Carbon::setTestNow('2026-08-20 12:00:00');

    $conversation = ChatConversation::factory()->create([
        'last_message_at' => now()->subHours(24),
    ]);
    $staleCandidate = $conversation->fresh();
    app(CloseChatConversation::class)->execute($conversation, $reason);

    $closed = app(CloseChatConversation::class)->closeIfInactive(
        $staleCandidate,
        now()->subHours(24),
    );

    expect($closed)->toBeFalse()
        ->and($conversation->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($conversation->fresh()->close_reason)->toBe($reason);
})->with([
    'customer restart' => ChatConversationCloseReason::CustomerStartedNew,
    'login claim' => ChatConversationCloseReason::SupersededByLoginClaim,
]);

test('chat maintenance is scheduled hourly without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains($event->command ?? '', 'chat:maintain-conversations'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
