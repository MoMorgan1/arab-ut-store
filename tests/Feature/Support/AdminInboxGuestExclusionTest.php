<?php

use App\Enums\UserRole;
use App\Models\ChatConversation;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

it('never lists a guest conversation', function (): void {
    ChatConversation::factory()->guest()->create();
    $customer = ChatConversation::factory()->forUser(User::factory()->create())->create();

    $this->actingAs(adminInboxTestActor())
        ->get('/admin/conversations')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 1)
            ->where('rows.0.publicId', (string) $customer->public_id));
});

it('404s on a guest transcript', function (): void {
    $guest = ChatConversation::factory()->guest()->create();

    $this->actingAs(adminInboxTestActor())
        ->get("/admin/conversations/{$guest->public_id}")
        ->assertNotFound();
});

function adminInboxTestActor(): User
{
    $actor = User::factory()->create([
        'role' => UserRole::Admin,
        'preferred_locale' => 'en',
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMININBOXEXCLUSIONTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
