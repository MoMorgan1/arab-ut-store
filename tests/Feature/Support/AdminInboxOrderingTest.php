<?php

use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Support\SupportTicketStatus;
use App\Enums\UserRole;
use App\Models\ChatConversation;
use App\Models\SupportTicket;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

function inboxAdmin(): User
{
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'preferred_locale' => 'en',
        'password' => 'SecurePassword!12',
    ]);

    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMININBOXORDERINGTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $admin;
}

/**
 * The queue is ordered, not filtered: a thread whose customer has written since
 * the last staff reply goes to the top however old it is, and everything else
 * stays visible underneath in activity order.
 */
it('floats waiting customers above more recent threads without hiding them', function (): void {
    $admin = inboxAdmin();

    // Newest activity, but already answered — must NOT be first.
    $answered = ChatConversation::factory()->forUser(User::factory()->create())->create([
        'last_message_at' => now(),
        'last_staff_message_at' => now()->addSecond(),
    ]);
    SupportTicket::factory()->for($answered, 'conversation')->for($answered->user, 'user')->create([
        'status' => SupportTicketStatus::Open,
    ]);

    // Much older, but the customer is waiting — must be first.
    $waiting = ChatConversation::factory()->forUser(User::factory()->create())->create([
        'last_message_at' => now()->subDays(3),
        'last_staff_message_at' => now()->subDays(4),
    ]);
    SupportTicket::factory()->for($waiting, 'conversation')->for($waiting->user, 'user')->create([
        'status' => SupportTicketStatus::Open,
    ]);

    // No ticket at all — an ordinary chat with the assistant, still listed.
    $plain = ChatConversation::factory()->forUser(User::factory()->create())->create([
        'last_message_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($admin)->get('/en/admin/conversations');
    $response->assertOk();

    $response->assertInertia(function (AssertableInertia $page) use ($waiting, $answered, $plain): void {
        $rows = $page->toArray()['props']['rows'];
        $ids = array_column($rows, 'shortId');

        expect($ids[0])->toBe($waiting->short_id)
            // Nothing is dropped: the ordering must not act as a filter.
            ->and($ids)->toContain($answered->short_id)
            ->and($ids)->toContain($plain->short_id);

        $byId = collect($rows)->keyBy('shortId');

        expect($byId[$waiting->short_id]['hasUnread'])->toBeTrue()
            ->and($byId[$answered->short_id]['hasUnread'])->toBeFalse()
            // The dot means "you owe them an answer", so a ticketless chat
            // never carries one however recently the customer wrote.
            ->and($byId[$plain->short_id]['hasUnread'])->toBeFalse()
            ->and($byId[$waiting->short_id]['ticketNumber'])->toStartWith('TKT-')
            ->and($byId[$plain->short_id]['ticketNumber'])->toBeNull()
            ->and($byId[$plain->short_id]['shortId'])->toStartWith('CHT-');
    });
});

it('finds a conversation by short id or ticket number, case-insensitively', function (): void {
    $admin = inboxAdmin();
    $customer = User::factory()->create();

    $conversation = ChatConversation::factory()->forUser($customer)->create();
    $ticket = SupportTicket::factory()->for($conversation, 'conversation')->for($customer, 'user')->create([
        'status' => SupportTicketStatus::Open,
    ]);

    ChatConversation::factory()->forUser(User::factory()->create())
        ->closed(ChatConversationCloseReason::CustomerStartedNew, now())
        ->create();

    foreach ([
        strtolower((string) $conversation->short_id),
        strtolower((string) $ticket->ticket_number),
    ] as $term) {
        $this->actingAs($admin)
            ->get('/en/admin/conversations?q='.urlencode($term))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($conversation, $term): void {
                $ids = array_column($page->toArray()['props']['rows'], 'shortId');

                expect($ids)->toBe([$conversation->short_id], "search term: {$term}");
            });
    }
});
