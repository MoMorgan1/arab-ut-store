<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password;

test('the bilingual security page exposes mode and recovery capability without hashes', function (
    string $path,
    string $locale,
): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'phone' => '+201001234567',
        'phone_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page
            ->component('account/security')
            ->where('locale', $locale)
            ->where('security.passwordMode', 'change')
            ->where('security.recoveryMode', 'email')
            ->where('security.recoveryUrl', fn (string $url): bool => str_contains($url, 'forgot-password'))
            ->where('accountNavigation', fn ($items): bool => collect($items)->pluck('key')->all() === [
                'overview', 'orders', 'wallet', 'profile', 'security',
            ])
            ->missing('security.password'));

    $payload = json_encode($response->inertiaPage(), JSON_THROW_ON_ERROR);
    expect($payload)->not->toContain($user->getAuthPassword());
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
    ])->assertRedirect('/my-account/security');

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
        ])->assertRedirect('/my-account/security');

    expect(Hash::check('SecurePassword12', $user->fresh()->password))->toBeTrue();

    $this->withSession(['auth.identity_confirmed_at' => now()->timestamp])
        ->post('/my-account/security/password', [
            'password' => 'AnotherPassword12',
            'password_confirmation' => 'AnotherPassword12',
        ])->assertForbidden();
});

test('passwordless accounts without verified email receive WhatsApp recovery guidance only', function (): void {
    $user = User::factory()->create([
        'password' => null,
        'email_verified_at' => null,
        'phone' => '+201001234567',
        'phone_verified_at' => now(),
    ]);

    $this->actingAs($user)->get('/my-account/security')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('security.passwordMode', 'setup')
            ->where('security.recoveryMode', 'whatsapp')
            ->where('security.recoveryUrl', config('store.support.whatsapp_url')));
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
