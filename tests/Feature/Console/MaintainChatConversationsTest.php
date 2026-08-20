<?php

use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('chat maintenance uses the approved lifecycle and retention defaults', function () {
    expect(config('chat.auto_close_hours'))->toBe(24)
        ->and(config('chat.reopen_within_days'))->toBe(7)
        ->and(config('chat.guest_retention_days'))->toBe(30)
        ->and(config('chat.user_retention_days'))->toBe(180);
});

test('chat maintenance closes inactive conversations and purges expired owner-specific conversations', function () {
    Carbon::setTestNow('2026-08-20 12:00:00');

    config()->set('chat.auto_close_hours', 24);
    config()->set('chat.guest_retention_days', 30);
    config()->set('chat.user_retention_days', 180);

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
    )->create();
    $expiredGuest = ChatConversation::factory()->forGuest($expiredGuestKey)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(30),
    )->create();

    $user = User::factory()->create();
    $retainedUser = ChatConversation::factory()->forUser($user)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(179),
    )->create();
    $expiredUser = ChatConversation::factory()->forUser(User::factory()->create())->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(180),
    )->create();

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
        ->doesntExpectOutput($expiredGuestKey)
        ->doesntExpectOutput($expiredGuestMessage)
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

test('chat maintenance is scheduled hourly without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains($event->command ?? '', 'chat:maintain-conversations'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
