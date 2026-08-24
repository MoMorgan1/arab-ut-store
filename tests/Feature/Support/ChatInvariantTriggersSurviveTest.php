<?php

use App\Models\ChatConversation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The support-handoff migration adds columns to chat_conversations. On SQLite,
 * altering a column rebuilds the table and silently drops its triggers — and the
 * one-open-conversation-per-owner invariant IS those triggers on SQLite. The
 * invariant would then fail open with no failing test to show for it, because the
 * only place it is enforced on SQLite is the thing that got dropped.
 *
 * These two tests are the tripwire for that whole class of bug.
 */
it('still has the active-owner triggers after every migration has run', function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        expect(true)->toBeTrue();

        return;
    }

    $triggers = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger'"))
        ->pluck('name')
        ->all();

    expect($triggers)->toContain('chat_conversations_derive_active_owner_insert')
        ->and($triggers)->toContain('chat_conversations_derive_active_owner_update');
});

it('still refuses a second open conversation for the same owner', function (): void {
    $guestKey = hash_hmac('sha256', str_repeat('a', 64), (string) config('app.key'));

    ChatConversation::factory()->forGuest($guestKey)->create();

    expect(fn () => ChatConversation::factory()->forGuest($guestKey)->create())
        ->toThrow(QueryException::class);
});
