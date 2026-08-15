<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('account login lands directly in localized My Account without the legacy dashboard hop', function (
    string $loginUrl,
    string $preferredLocale,
    string $accountUrl,
) {
    $user = User::factory()->create(['preferred_locale' => $preferredLocale]);

    $response = $this->post($loginUrl, [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect($accountUrl);
})->with([
    'Arabic account' => ['/login', 'ar', '/my-account'],
    'English account' => ['/en/login', 'en', '/en/my-account'],
]);

test('phone numbers cannot bypass the one-time-code login flow with a password', function () {
    $user = User::factory()->create(['phone' => '+201001234567']);

    $response = $this->post(route('login.store'), [
        'email' => $user->phone,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors();
});

test('inactive users cannot authenticate with email', function () {
    $user = User::factory()->create([
        'phone' => '+966501234567',
        'is_active' => false,
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
