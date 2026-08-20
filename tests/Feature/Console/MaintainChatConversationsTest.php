<?php

use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;

test('maintenance closes conversations inactive for at least twenty-four hours at the boundary', function () {
    $now = now()->startOfSecond();
    $this->travelTo($now);

    $active = ChatConversation::factory()->forGuest(hash('sha256', 'active-conversation'))
        ->create(['last_message_at' => $now->copy()->subHours(23)]);
    $inactive = ChatConversation::factory()->forUser(User::factory()->create())
        ->create(['last_message_at' => $now->copy()->subHours(24)]);

    $this->artisan('chat:maintain-conversations')
        ->expectsOutputToContain('Closed 1 inactive conversation(s).')
        ->expectsOutputToContain('Purged 0 expired guest conversation(s).')
        ->expectsOutputToContain('Purged 0 expired authenticated conversation(s).')
        ->assertSuccessful();

    expect($active->fresh()->status)->toBe(ChatConversationStatus::Open)
        ->and($inactive->fresh()->status)->toBe(ChatConversationStatus::Closed)
        ->and($inactive->fresh()->close_reason)->toBe(ChatConversationCloseReason::Inactive)
        ->and($inactive->fresh()->closed_at?->equalTo($now))->toBeTrue();
});

test('maintenance purges expired closed conversations at guest and authenticated retention boundaries without outputting private data', function () {
    $now = now()->startOfSecond();
    $this->travelTo($now);

    $guestKey = hash('sha256', 'guest-retention-output-sentinel');
    $messageContent = 'guest-message-output-sentinel';
    $expiredGuest = ChatConversation::factory()->forGuest($guestKey)
        ->closed(ChatConversationCloseReason::Inactive, $now->copy()->subDay())
        ->create(['last_message_at' => $now->copy()->subDays(30)]);
    $expiredGuestMessage = ChatMessage::factory()->for($expiredGuest, 'conversation')
        ->create(['content' => $messageContent]);
    $retainedGuest = ChatConversation::factory()->forGuest(hash('sha256', 'guest-retained'))
        ->closed(ChatConversationCloseReason::Inactive, $now->copy()->subDay())
        ->create(['last_message_at' => $now->copy()->subDays(29)]);

    $expiredUser = ChatConversation::factory()->forUser(User::factory()->create())
        ->closed(ChatConversationCloseReason::Inactive, $now->copy()->subDay())
        ->create(['last_message_at' => $now->copy()->subDays(180)]);
    $expiredUserMessage = ChatMessage::factory()->for($expiredUser, 'conversation')
        ->create(['content' => 'authenticated-message-output-sentinel']);
    $retainedUser = ChatConversation::factory()->forUser(User::factory()->create())
        ->closed(ChatConversationCloseReason::Inactive, $now->copy()->subDay())
        ->create(['last_message_at' => $now->copy()->subDays(179)]);

    $this->artisan('chat:maintain-conversations')
        ->expectsOutputToContain('Closed 0 inactive conversation(s).')
        ->expectsOutputToContain('Purged 1 expired guest conversation(s).')
        ->expectsOutputToContain('Purged 1 expired authenticated conversation(s).')
        ->doesntExpectOutput($guestKey)
        ->doesntExpectOutput($messageContent)
        ->assertSuccessful();

    expect($expiredGuest->fresh())->toBeNull()
        ->and($expiredGuestMessage->fresh())->toBeNull()
        ->and($retainedGuest->fresh())->not->toBeNull()
        ->and($expiredUser->fresh())->toBeNull()
        ->and($expiredUserMessage->fresh())->toBeNull()
        ->and($retainedUser->fresh())->not->toBeNull();
});

test('maintenance is idempotent and deletes expired guest conversations across the chunk boundary', function () {
    $now = now()->startOfSecond();
    $this->travelTo($now);

    ChatConversation::factory()
        ->count(201)
        ->sequence(fn ($sequence) => [
            'guest_key' => hash('sha256', 'expired-guest-'.$sequence->index),
            'status' => ChatConversationStatus::Closed,
            'closed_at' => $now->copy()->subDay(),
            'close_reason' => ChatConversationCloseReason::Inactive,
            'last_message_at' => $now->copy()->subDays(30),
        ])
        ->create();

    $this->artisan('chat:maintain-conversations')
        ->expectsOutputToContain('Purged 201 expired guest conversation(s).')
        ->assertSuccessful();

    $this->artisan('chat:maintain-conversations')
        ->expectsOutputToContain('Closed 0 inactive conversation(s).')
        ->expectsOutputToContain('Purged 0 expired guest conversation(s).')
        ->expectsOutputToContain('Purged 0 expired authenticated conversation(s).')
        ->assertSuccessful();

    expect(ChatConversation::query()->count())->toBe(0);
});

test('lifecycle maintenance exposes the approved configuration and an hourly non-overlapping schedule', function () {
    expect(config('chat.auto_close_hours'))->toBe(24)
        ->and(config('chat.reopen_within_days'))->toBe(7)
        ->and(config('chat.guest_retention_days'))->toBe(30)
        ->and(config('chat.user_retention_days'))->toBe(180);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains($event->command ?? '', 'chat:maintain-conversations'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
