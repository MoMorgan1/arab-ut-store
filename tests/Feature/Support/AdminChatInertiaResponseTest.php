<?php

use App\Enums\Chat\ChatSenderType;
use App\Enums\Support\SupportTicketStatus;
use App\Enums\UserRole;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Laravel\Fortify\Fortify;

beforeEach(function () {
    config()->set('chat.enabled', true);
});

function inertiaAdmin(): User
{
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'preferred_locale' => 'en',
        'password' => 'SecurePassword!12',
    ]);

    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINCHATINERTIATOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $admin;
}

function conversationWithCustomerMessage(): ChatConversation
{
    $conversation = ChatConversation::factory()->forUser(User::factory()->create())->create();

    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'content' => 'Where is my order?',
    ]);

    return $conversation;
}

/**
 * The admin pages are Inertia, and Inertia rejects a plain JSON body with
 * "All Inertia requests must receive a valid Inertia response". These four
 * endpoints answered every caller in JSON, so in production the reply was
 * written correctly and then threw an error modal over the operator — the
 * feature was unusable despite the data being right.
 */
it('answers an Inertia visit with a redirect, not JSON', function (string $method, string $path): void {
    $admin = inertiaAdmin();
    $conversation = conversationWithCustomerMessage();

    $url = $path === 'ticket'
        ? null
        : "/en/admin/conversations/{$conversation->public_id}/{$path}";

    if ($url === null) {
        $ticket = SupportTicket::factory()
            ->for($conversation, 'conversation')
            ->for($conversation->user, 'user')
            ->create(['status' => SupportTicketStatus::Open]);

        $url = "/en/admin/tickets/{$ticket->public_id}";
    }

    // call() does not apply withHeaders(); post()/patch() take them directly.
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => ''];
    $payload = ['content' => 'On it now.', 'status' => 'resolved'];

    $response = $method === 'PATCH'
        ? $this->actingAs($admin)->patch($url, $payload, $headers)
        : $this->actingAs($admin)->post($url, $payload, $headers);

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(300)
        ->and($response->getStatusCode())->toBeLessThan(400)
        ->and($response->headers->get('content-type'))->not->toContain('application/json');
})->with([
    'reply' => ['POST', 'reply'],
    'note' => ['POST', 'note'],
    'take over' => ['POST', 'take-over'],
    'resolve ticket' => ['PATCH', 'ticket'],
]);

it('still answers a non-Inertia API caller with JSON', function (): void {
    $admin = inertiaAdmin();
    $conversation = conversationWithCustomerMessage();

    $this->actingAs($admin)
        ->postJson("/en/admin/conversations/{$conversation->public_id}/reply", [
            'content' => 'On it now.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.message.senderType', 'staff');
});
