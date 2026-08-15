<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password;

test('the legacy security URLs redirect customers to the profile page', function (
    string $path,
    string $locale,
): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'phone' => '+201001234567',
        'phone_verified_at' => now(),
    ]);

    $this->actingAs($user)->get($path)
        ->assertRedirect($locale === 'en' ? '/en/my-account/profile' : '/my-account/profile');
})->with([
    'Arabic security' => ['/my-account/security', 'ar'],
    'English security' => ['/en/my-account/security', 'en'],
]);

test('password changes require the current password and the configured password policy', function (): void {
    Password::defaults(fn (): Password => Password::min(12)->mixedCase()->numbers());
    $user = User::factory()->create();

    $this->actingAs($user)->put('/my-account/security/password', [
        'current_password' => 'wrong-password',
        'password' => 'SecurePassword12',
        'password_confirmation' => 'SecurePassword12',
    ])->assertSessionHasErrors('current_password');

    $this->put('/my-account/security/password', [
        'current_password' => 'password',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    $this->put('/my-account/security/password', [
        'current_password' => 'password',
        'password' => 'SecurePassword12',
        'password_confirmation' => 'SecurePassword12',
    ])->assertRedirect('/my-account/profile');

    expect(Hash::check('SecurePassword12', $user->fresh()->password))->toBeTrue();
});

test('password setup is limited to passwordless accounts with recent trusted verification', function (): void {
    $user = User::factory()->create([
        'password' => null,
        'email_verified_at' => null,
        'phone' => '+201001234567',
        'phone_verified_at' => now(),
    ]);

    $this->actingAs($user)->post('/my-account/security/password', [
        'password' => 'SecurePassword12',
        'password_confirmation' => 'SecurePassword12',
    ])->assertForbidden();

    $this->withSession(['auth.identity_confirmed_at' => now()->timestamp])
        ->post('/my-account/security/password', [
            'password' => 'SecurePassword12',
            'password_confirmation' => 'SecurePassword12',
        ])->assertRedirect('/my-account/profile');

    expect(Hash::check('SecurePassword12', $user->fresh()->password))->toBeTrue();

    $this->withSession(['auth.identity_confirmed_at' => now()->timestamp])
        ->post('/my-account/security/password', [
            'password' => 'AnotherPassword12',
            'password_confirmation' => 'AnotherPassword12',
        ])->assertForbidden();
});

test('the old security destination no longer renders a duplicate password screen', function (): void {
    $user = User::factory()->create([
        'password' => null,
        'email_verified_at' => null,
        'phone' => '+201001234567',
        'phone_verified_at' => now(),
    ]);

    $this->actingAs($user)->get('/my-account/security')
        ->assertRedirect('/my-account/profile');
});

test('standard email recovery stays generic but sends only to a verified active email', function (string $path): void {
    Notification::fake();
    $unverified = User::factory()->unverified()->create();

    $this->post($path, ['email' => $unverified->email])
        ->assertRedirect();
    Notification::assertNothingSent();

    $inactive = User::factory()->create(['is_active' => false]);
    $this->post($path, ['email' => $inactive->email])
        ->assertRedirect();
    Notification::assertNothingSent();

    $verified = User::factory()->create();
    $this->post($path, ['email' => $verified->email])
        ->assertRedirect();
    Notification::assertSentTo($verified, ResetPassword::class);
})->with([
    'Arabic recovery' => '/forgot-password',
    'English recovery' => '/en/forgot-password',
]);
