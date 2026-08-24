<?php

use App\Actions\Support\OpenSupportTicket;
use App\Enums\Chat\ChatHandoffState;
use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Enums\Support\SupportTicketStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureAdminMfa;
use App\Http\Presenters\ChatPresenter;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\StaffAuditLog;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;

beforeEach(function (): void {
    config()->set('chat.enabled', true);
    config()->set('chat.max_message_length', 4000);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('protects admin support routes behind EnsureAdminMfa and can:chat.reply middleware', function (): void {
    $routes = [
        'admin.conversations.reply' => ['POST', 'conversations/{publicId}/reply'],
        'admin.conversations.note' => ['POST', 'conversations/{publicId}/note'],
        'admin.conversations.take-over' => ['POST', 'conversations/{publicId}/take-over'],
        'admin.tickets.resolve' => ['PATCH', 'tickets/{publicId}'],
    ];

    foreach ($routes as $name => [$method, $uri]) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull()
            ->and($route?->gatherMiddleware())->toContain(EnsureAdminMfa::class)
            ->and($route?->gatherMiddleware())->toContain('can:chat.reply');
    }
});

it('denies UserRole::Staff from chat.reply endpoints with 403', function (): void {
    $staff = adminSupportActor(UserRole::Staff);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();
    $ticket = SupportTicket::factory()->for($conversation, 'conversation')->for($customer, 'user')->open()->create();

    $this->actingAs($staff)
        ->postJson("/admin/conversations/{$conversation->public_id}/take-over")
        ->assertForbidden();

    $this->actingAs($staff)
        ->postJson("/admin/conversations/{$conversation->public_id}/reply", ['content' => 'Staff reply attempt'])
        ->assertForbidden();

    $this->actingAs($staff)
        ->postJson("/admin/conversations/{$conversation->public_id}/note", ['content' => 'Staff note attempt'])
        ->assertForbidden();

    $this->actingAs($staff)
        ->patchJson("/admin/tickets/{$ticket->public_id}")
        ->assertForbidden();
});

it('404s on guest conversations across all staff endpoints', function (): void {
    $admin = adminSupportActor(UserRole::Admin);
    $guestConversation = ChatConversation::factory()->guest()->create();
    $guestTicket = SupportTicket::factory()->for($guestConversation, 'conversation')->state([
        'user_id' => null,
    ])->open()->make();

    // Guest tickets cannot exist in database due to foreign key NOT NULL, but test conversation lookups
    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$guestConversation->public_id}/take-over")
        ->assertNotFound();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$guestConversation->public_id}/reply", ['content' => 'Hello guest'])
        ->assertNotFound();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$guestConversation->public_id}/note", ['content' => 'Note on guest'])
        ->assertNotFound();
});

it('allows an admin to take over an unassigned conversation and marks handoff active', function (): void {
    $admin = adminSupportActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create([
        'handoff_state' => ChatHandoffState::None,
    ]);

    ChatMessage::factory()->customer()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Need help with coins delivery',
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/take-over");

    $response->assertOk()
        ->assertJsonPath('data.ticket.status', 'open')
        ->assertJsonPath('data.ticket.assignedAdminId', $admin->id)
        ->assertJsonPath('data.handoffState', 'active');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($conversation->fresh()->handoff_state)->toBe(ChatHandoffState::Active);

    $ticket = SupportTicket::query()->where('conversation_id', $conversation->id)->first();
    expect($ticket)->not->toBeNull()
        ->and($ticket->assigned_admin_id)->toBe($admin->id)
        ->and($ticket->subject)->toBe('Need help with coins delivery');

    // Verify audit event
    $audit = StaffAuditLog::query()->where('action', 'chat.ticket.assigned')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->metadata['ticket_number'])->toBe((string) $ticket->ticket_number)
        ->and($audit->metadata['conversation_short_id'])->toBe((string) $conversation->short_id)
        ->and($audit->metadata['target_user_id'])->toBe($customer->id);
});

it('is idempotent when the same admin takes over a conversation multiple times', function (): void {
    $admin = adminSupportActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $firstResponse = $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/take-over")
        ->assertOk();

    $firstTicketNumber = $firstResponse->json('data.ticket.ticketNumber');

    $secondResponse = $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/take-over")
        ->assertOk();

    expect($secondResponse->json('data.ticket.ticketNumber'))->toBe($firstTicketNumber)
        ->and(SupportTicket::query()->where('conversation_id', $conversation->id)->count())->toBe(1);
});

it('returns 409 when a second admin attempts to take over a ticket active under another admin', function (): void {
    $admin1 = adminSupportActor(UserRole::Admin);
    $admin2 = adminSupportActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $this->actingAs($admin1)
        ->postJson("/admin/conversations/{$conversation->public_id}/take-over")
        ->assertOk();

    $response = $this->actingAs($admin2)
        ->postJson("/admin/conversations/{$conversation->public_id}/take-over");

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'ticket_already_assigned');

    $ticket = SupportTicket::query()->where('conversation_id', $conversation->id)->first();
    expect($ticket?->assigned_admin_id)->toBe($admin1->id);
});

it('opens a ticket and sets handoff_state = active implicitly when replying with no prior ticket', function (): void {
    $admin = adminSupportActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create([
        'handoff_state' => ChatHandoffState::None,
        'last_message_at' => now()->subMinutes(10),
        'last_staff_message_at' => null,
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/reply", [
            'content' => 'Hello! We are looking into your order right now.',
            'client_message_id' => 'client-msg-12345',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.message.senderType', 'staff')
        ->assertJsonPath('data.message.messageType', 'text')
        ->assertJsonPath('data.message.content', 'Hello! We are looking into your order right now.')
        ->assertJsonPath('data.ticket.status', 'open')
        ->assertJsonPath('data.handoffState', 'active');

    $freshConversation = $conversation->fresh();
    expect($freshConversation->handoff_state)->toBe(ChatHandoffState::Active)
        ->and($freshConversation->last_staff_message_at)->not->toBeNull()
        ->and($freshConversation->last_message_at)->not->toBeNull();

    $message = ChatMessage::query()->where('conversation_id', $conversation->id)->first();
    expect($message)->not->toBeNull()
        ->and($message->sender_type)->toBe(ChatSenderType::Staff)
        ->and($message->staff_user_id)->toBe($admin->id)
        ->and($message->reply_to_message_id)->toBeNull();

    // Verify audit event has character count and no message body
    $audit = StaffAuditLog::query()->where('action', 'chat.reply.sent')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->metadata['character_count'])->toBe(mb_strlen('Hello! We are looking into your order right now.'))
        ->and($audit->metadata)->not->toHaveKey('content')
        ->and($audit->metadata)->not->toHaveKey('body');
});

it('returns 409 when replying to a conversation already assigned to a different admin', function (): void {
    $admin1 = adminSupportActor(UserRole::Admin);
    $admin2 = adminSupportActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $this->actingAs($admin1)
        ->postJson("/admin/conversations/{$conversation->public_id}/take-over")
        ->assertOk();

    $response = $this->actingAs($admin2)
        ->postJson("/admin/conversations/{$conversation->public_id}/reply", [
            'content' => 'Competing reply from another admin',
        ]);

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'ticket_already_assigned');
});

it('allows adding an internal note that leaves timestamps and handoff_state untouched', function (): void {
    $admin = adminSupportActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $fixedTime = Carbon::parse('2026-08-24 10:00:00', 'UTC');
    $conversation = ChatConversation::factory()->forUser($customer)->create([
        'handoff_state' => ChatHandoffState::None,
        'last_message_at' => $fixedTime,
        'last_staff_message_at' => null,
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/note", [
            'content' => 'Customer mentioned issue with PayPal account.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.message.senderType', 'staff')
        ->assertJsonPath('data.message.messageType', 'internal_note')
        ->assertJsonPath('data.message.content', 'Customer mentioned issue with PayPal account.');

    $freshConversation = $conversation->fresh();
    expect($freshConversation->handoff_state)->toBe(ChatHandoffState::None)
        ->and($freshConversation->last_staff_message_at)->toBeNull()
        ->and($freshConversation->last_message_at?->toIso8601String())->toBe($fixedTime->toIso8601String());

    $note = ChatMessage::query()->where('conversation_id', $conversation->id)->first();
    expect($note)->not->toBeNull()
        ->and($note->sender_type)->toBe(ChatSenderType::Staff)
        ->and($note->message_type)->toBe(ChatMessageType::InternalNote)
        ->and($note->staff_user_id)->toBe($admin->id)
        ->and($note->reply_to_message_id)->toBeNull();

    // Verify audit event
    $audit = StaffAuditLog::query()->where('action', 'chat.note.added')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->metadata['character_count'])->toBe(mb_strlen('Customer mentioned issue with PayPal account.'))
        ->and($audit->metadata)->not->toHaveKey('content');
});

it('ensures an internal note is absent from customer conversation JSON', function (): void {
    $admin = adminSupportActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    ChatMessage::factory()->customer()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Public customer query',
    ]);

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/note", [
            'content' => 'HIGHLY-CONFIDENTIAL-STAFF-NOTE-12345',
        ])
        ->assertCreated();

    $loaded = app(ChatPresenter::class)->loadBoundedMessages($conversation);
    $payload = json_encode($loaded['messages']->all(), JSON_THROW_ON_ERROR);

    expect($payload)->not->toContain('HIGHLY-CONFIDENTIAL-STAFF-NOTE-12345')
        ->and($payload)->toContain('Public customer query');
});

it('validates message length and rejects empty content on reply and note endpoints', function (): void {
    $admin = adminSupportActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $oversized = str_repeat('a', 4001);

    // Empty content on reply
    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/reply", ['content' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content']);

    // Oversized content on reply
    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/reply", ['content' => $oversized])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content']);

    // Empty content on note
    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/note", ['content' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content']);

    // Oversized content on note
    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/note", ['content' => $oversized])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content']);
});

it('resolves a ticket under conversation lock, posts resumption notice, and allows reopening', function (): void {
    $admin = adminSupportActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create(['locale' => 'ar']);

    // Open ticket via take-over
    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/take-over")
        ->assertOk();

    $ticket = SupportTicket::query()->where('conversation_id', $conversation->id)->firstOrFail();

    // Resolve ticket via PATCH /admin/tickets/{publicId}
    $response = $this->actingAs($admin)
        ->patchJson("/admin/tickets/{$ticket->public_id}");

    $response->assertOk()
        ->assertJsonPath('data.ticket.status', 'resolved')
        ->assertJsonPath('data.handoffState', 'resolved');

    expect($ticket->fresh()->status)->toBe(SupportTicketStatus::Resolved)
        ->and($ticket->fresh()->resolved_at)->not->toBeNull()
        ->and($conversation->fresh()->handoff_state)->toBe(ChatHandoffState::Resolved);

    // Resumption system message is appended
    $resumptionMessage = $conversation->fresh()->messages()->latest('id')->first();
    expect($resumptionMessage->sender_type)->toBe(ChatSenderType::System)
        ->and($resumptionMessage->content)->toContain('نواف رجع لمساعدتك');

    // Audit event emitted
    $audit = StaffAuditLog::query()->where('action', 'chat.ticket.resolved')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->metadata['ticket_number'])->toBe((string) $ticket->ticket_number);

    // Reopen succeeds: opening a new ticket on the resolved conversation succeeds without duplicate key collision
    $newTicket = app(OpenSupportTicket::class)->execute($conversation->fresh(), $customer);
    expect($newTicket->id)->not->toBe($ticket->id)
        ->and($newTicket->status)->toBe(SupportTicketStatus::Open)
        ->and($conversation->fresh()->handoff_state)->toBe(ChatHandoffState::Requested);
});

it('confirms all audit events carry no message body or secret transcript data', function (): void {
    $admin = adminSupportActor(UserRole::Admin);
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $conversation = ChatConversation::factory()->forUser($customer)->create();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/reply", [
            'content' => 'Confidential customer reply message body',
        ])
        ->assertCreated();

    $this->actingAs($admin)
        ->postJson("/admin/conversations/{$conversation->public_id}/note", [
            'content' => 'Confidential internal note text',
        ])
        ->assertCreated();

    $logs = StaffAuditLog::query()
        ->whereIn('action', ['chat.reply.sent', 'chat.note.added'])
        ->get();

    expect($logs)->toHaveCount(2);

    foreach ($logs as $log) {
        $encoded = json_encode($log->metadata, JSON_THROW_ON_ERROR);
        expect($encoded)->not->toContain('Confidential')
            ->and($encoded)->not->toContain('reply message body')
            ->and($encoded)->not->toContain('note text')
            ->and($log->metadata)->not->toHaveKey('content')
            ->and($log->metadata)->not->toHaveKey('body')
            ->and($log->metadata)->not->toHaveKey('text')
            ->and($log->metadata)->not->toHaveKey('message');
    }
});

function adminSupportActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINSUPPORTENDPOINTSSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
