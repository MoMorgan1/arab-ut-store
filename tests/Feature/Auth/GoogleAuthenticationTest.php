<?php

use App\Enums\UserRole;
use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config()->set('services.google.client_id', 'google-client');
    config()->set('services.google.client_secret', 'google-secret');
    config()->set('services.google.redirect', 'https://store.example.test/auth/google/callback');
});

test('google authentication redirects through the official provider', function () {
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect()->away('https://accounts.google.test/oauth'));
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->get('/auth/google/redirect')
        ->assertRedirect('https://accounts.google.test/oauth');
});

test('verified google identity creates a customer and social account without storing oauth tokens', function () {
    $providerUser = (new SocialiteUser)->map([
        'id' => 'google-user-123',
        'name' => 'Mohamed Player',
        'email' => 'google-player@example.test',
        'email_verified' => true,
    ])->setRaw([
        'email_verified' => true,
    ]);
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn($providerUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->get('/auth/google/callback')->assertRedirect('/my-account');

    $user = User::where('email', 'google-player@example.test')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->not->toBeNull()
        ->and(SocialAccount::query()->where([
            'provider' => 'google',
            'provider_user_id' => 'google-user-123',
            'user_id' => $user->id,
        ])->exists())->toBeTrue()
        ->and(SocialAccount::query()->firstOrFail()->getAttributes())
        ->not->toHaveKeys(['token', 'refresh_token']);
});

test('google callback refuses to link an unverified provider email to an existing account', function () {
    $existing = User::factory()->create(['email' => 'existing@example.test']);
    $providerUser = (new SocialiteUser)->map([
        'id' => 'unverified-google-user',
        'name' => 'Unverified Player',
        'email' => $existing->email,
        'email_verified' => false,
    ])->setRaw([
        'email_verified' => false,
    ]);
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn($providerUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $this->get('/auth/google/callback')->assertRedirect('/login');

    $this->assertGuest();
    expect(SocialAccount::query()->count())->toBe(0);
});

test('google callback signs in privileged accounts without revealing their role', function (UserRole $role) {
    $user = User::factory()->create([
        'email' => 'privileged-google@example.test',
        'role' => $role,
    ]);
    $providerUser = (new SocialiteUser)->map([
        'id' => 'privileged-google-user',
        'name' => 'Privileged Player',
        'email' => $user->email,
        'email_verified' => true,
    ])->setRaw([
        'email_verified' => true,
    ]);
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn($providerUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    $response = $this->get('/auth/google/callback');

    $response->assertSessionDoesntHaveErrors()
        ->assertDontSee($role->value);
    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::query()->where('provider_user_id', 'privileged-google-user')->exists())
        ->toBeTrue();
})->with([
    'Admin' => [UserRole::Admin],
    'Staff' => [UserRole::Staff],
]);
