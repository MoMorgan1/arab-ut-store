<?php

use App\Enums\Support\SupportTicketStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureAdminMfa;
use App\Models\ChatConversation;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

it('rejects unauthenticated requests with 302/401 redirect to login', function (): void {
    $response = $this->getJson(route('admin.support.unread-count'));

    $response->assertUnauthorized();
});

it('sits behind EnsureAdminMfa and can:chat.view', function (): void {
    $route = Route::getRoutes()->getByName('admin.support.unread-count');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(EnsureAdminMfa::class)
        ->and($route?->gatherMiddleware())->toContain('can:chat.view');
});

it('rejects users without chat.view permission with 403', function (): void {
    $staff = unreadCountActor(UserRole::Staff);

    $this->actingAs($staff)->getJson(route('admin.support.unread-count'))->assertForbidden();
});

it('counts only live tickets whose customer spoke after the last staff reply', function (): void {
    $admin = unreadCountActor(UserRole::Admin);

    // Unread: never answered by a human at all.
    $neverAnswered = ChatConversation::factory()->create([
        'last_message_at' => now(),
        'last_staff_message_at' => null,
    ]);

    // Unread: the customer wrote back after the last staff reply.
    $customerRepliedBack = ChatConversation::factory()->create([
        'last_message_at' => now(),
        'last_staff_message_at' => now()->subMinutes(10),
    ]);

    // Read: the staff reply is the newest thing in the thread.
    $alreadyAnswered = ChatConversation::factory()->create([
        'last_message_at' => now()->subMinutes(10),
        'last_staff_message_at' => now(),
    ]);

    // Not live: resolved tickets never contribute, unread or not.
    $resolved = ChatConversation::factory()->create([
        'last_message_at' => now(),
        'last_staff_message_at' => null,
    ]);

    foreach ([$neverAnswered, $customerRepliedBack, $alreadyAnswered] as $conversation) {
        SupportTicket::factory()->for($conversation, 'conversation')->create([
            'status' => SupportTicketStatus::Open,
        ]);
    }

    SupportTicket::factory()->for($resolved, 'conversation')->create([
        'status' => SupportTicketStatus::Resolved,
    ]);

    $response = $this->actingAs($admin)->getJson(route('admin.support.unread-count'));

    $response->assertOk()->assertJson(['count' => 2]);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

function unreadCountActor(UserRole $role): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => 'en',
        'password' => 'SecurePassword!12',
    ]);

    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('SUPPORTUNREADCOUNTTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
