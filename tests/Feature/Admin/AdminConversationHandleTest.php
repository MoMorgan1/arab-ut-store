<?php

use App\Enums\Chat\ChatConversationStatus;
use App\Enums\UserRole;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\PublicHandle\ConversationHandle;
use App\Support\PublicHandle\TicketHandle;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

test('a legacy ULID admin conversation URL is permanently redirected to the short id', function (string $prefix): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $this->actingAs($admin)
        ->get("{$prefix}/conversations/{$conversation->public_id}")
        ->assertStatus(301)
        ->assertRedirect("{$prefix}/conversations/{$conversation->short_id}");
})->with([
    'default prefix' => ['/admin'],
    'localized prefix' => ['/en/admin'],
]);

test('the conversation handle helper falls back to the internal public id when a thread has no short id', function (): void {
    $publicId = (string) Str::ulid();

    expect(ConversationHandle::handleForValues(null, $publicId))->toBe($publicId)
        ->and(ConversationHandle::handleForValues('', $publicId))->toBe($publicId)
        ->and(ConversationHandle::handleForValues('CHT-AB12CD', $publicId))->toBe('CHT-AB12CD');
});

test('the ticket handle helper falls back to the internal public id when a ticket has no number', function (): void {
    $publicId = (string) Str::ulid();

    expect(TicketHandle::handleForValues(null, $publicId))->toBe($publicId)
        ->and(TicketHandle::handleForValues('', $publicId))->toBe($publicId)
        ->and(TicketHandle::handleForValues('TKT-AB12CD', $publicId))->toBe('TKT-AB12CD');
});

test('the admin conversation detail resolves by the short id', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $this->actingAs($admin)
        ->get('/admin/conversations/'.$conversation->short_id)
        ->assertOk();
});

test('a lowercase short id resolves in place without a canonicalizing redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $this->actingAs($admin)
        ->get('/admin/conversations/'.mb_strtolower((string) $conversation->short_id))
        ->assertOk();
});

test('the admin conversation detail resolves by the short id under the localized prefix', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $this->actingAs($admin)
        ->get('/en/admin/conversations/'.$conversation->short_id)
        ->assertOk();
});

test('an unknown conversation handle returns 404', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get('/admin/conversations/CHT-ZZZZZZ')
        ->assertNotFound();
});

test('a legacy ULID admin conversation URL keeps its query string through the redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $this->actingAs($admin)
        ->get("/admin/conversations/{$conversation->public_id}?status=open")
        ->assertStatus(301)
        ->assertRedirect('/admin/conversations/'.$conversation->short_id.'?status=open');
});

test('reply, note, and take-over mutations resolve a legacy ULID without a canonicalizing redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/take-over")
        ->assertOk();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/reply", ['content' => 'Resolved reply'])
        ->assertCreated();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/note", ['content' => 'Internal note'])
        ->assertCreated();
});

test('reply, note, and take-over mutations resolve the short id', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->short_id}/take-over")
        ->assertOk();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->short_id}/reply", ['content' => 'Resolved reply'])
        ->assertCreated();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->short_id}/note", ['content' => 'Internal note'])
        ->assertCreated();
});

test('an unknown well-formed ULID returns 404 from every conversation mutation endpoint', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $unknown = (string) Str::ulid();

    $this->actingAs($admin)
        ->get("/admin/conversations/{$unknown}")
        ->assertNotFound();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$unknown}/reply", ['content' => 'Ghost reply'])
        ->assertNotFound();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$unknown}/note", ['content' => 'Ghost note'])
        ->assertNotFound();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$unknown}/take-over")
        ->assertNotFound();
});

test('the admin ticket resolve resolves by the ticket number', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();
    $ticket = SupportTicket::factory()
        ->for($conversation, 'conversation')
        ->for($customer, 'user')
        ->open()
        ->create();

    $this->actingAs($admin)
        ->patchJson("/admin/tickets/{$ticket->ticket_number}")
        ->assertOk()
        ->assertJsonPath('data.ticket.status', 'resolved');
});

test('the admin ticket resolve resolves a legacy ULID without a canonicalizing redirect', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();
    $ticket = SupportTicket::factory()
        ->for($conversation, 'conversation')
        ->for($customer, 'user')
        ->open()
        ->create();

    $this->actingAs($admin)
        ->patchJson("/admin/tickets/{$ticket->public_id}")
        ->assertOk();
});

test('an unknown ticket handle returns 404', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);

    $this->actingAs($admin)
        ->patchJson('/admin/tickets/TKT-ZZZZZZ')
        ->assertNotFound();
});

test('an unknown well-formed ULID returns 404 from the ticket resolve endpoint', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $unknown = (string) Str::ulid();

    $this->actingAs($admin)
        ->patchJson("/admin/tickets/{$unknown}")
        ->assertNotFound();
});

test('admin search matches a short id or ticket number regardless of case', function (string $case): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $ticket = SupportTicket::factory()
        ->for($conversation, 'conversation')
        ->for($customer, 'user')
        ->open()
        ->create();

    $term = match ($case) {
        'uppercase short id' => (string) $conversation->short_id,
        'lowercase short id' => mb_strtolower((string) $conversation->short_id),
        'lowercase ticket number' => mb_strtolower((string) $ticket->ticket_number),
    };

    $response = $this->actingAs($admin)
        ->get('/admin/conversations?q='.urlencode($term));
    $response->assertOk();

    $rows = $response->original->getData()['page']['props']['rows'];
    $shortIds = array_column($rows, 'shortId');

    expect($shortIds)->toContain((string) $conversation->short_id);
})->with([
    'uppercase short id',
    'lowercase short id',
    'lowercase ticket number',
]);

test('admin search does not match the internal conversation ULID', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $response = $this->actingAs($admin)
        ->get('/admin/conversations?q='.$conversation->public_id);
    $response->assertOk();

    $rows = $response->original->getData()['page']['props']['rows'];

    expect($rows)->toBe([]);
});

test('admin conversation surfaces never emit an internal ULID href', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $conversation = ChatConversation::factory()->forUser($customer)->create([
        'status' => ChatConversationStatus::Open,
    ]);

    ChatMessage::factory()->customer()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Where is my order?',
    ]);

    $turn = AgentTurn::factory()->create([
        'conversation_id' => $conversation->id,
    ]);

    AgentRun::factory()->create(['agent_turn_id' => $turn->id]);

    SupportTicket::factory()
        ->for($conversation, 'conversation')
        ->for($customer, 'user')
        ->open()
        ->create();

    $responses = [
        $this->actingAs($admin)->get('/admin/conversations')->assertOk(),
        $this->actingAs($admin)->get('/admin/conversations/'.$conversation->short_id)->assertOk(),
    ];

    foreach ($responses as $response) {
        assertNoInternalSupportUlids(adminConversationHandleProps($response), [
            (string) $conversation->public_id,
            (string) $turn->public_id,
        ]);
    }
});

/**
 * @return array<string, mixed>
 */
function adminConversationHandleProps(TestResponse $response): array
{
    /** @var array{props?: array<string, mixed>} $page */
    $page = $response->viewData('page');

    return $page['props'] ?? [];
}

/**
 * Walk the whole prop tree and assert it carries neither an admin URL built
 * from an internal ULID nor the raw ULID itself: a link shaped
 * `/admin/conversations/{ULID}` is the leak the short ids exist to remove, and
 * a bare conversation or turn ULID in any string value is the same leak in a
 * different shape.
 *
 * @param  list<string>  $internalIds
 */
function assertNoInternalSupportUlids(mixed $value, array $internalIds = []): void
{
    if (is_string($value)) {
        expect($value)->not->toMatch('#/conversations/[0-9A-HJKMNP-TV-Z]{26}#i')
            ->and($value)->not->toMatch('#/tickets/[0-9A-HJKMNP-TV-Z]{26}#i');

        if ($internalIds !== []) {
            expect($value)->not->toBeIn($internalIds);
        }

        return;
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            assertNoInternalSupportUlids($item, $internalIds);
        }
    }
}
