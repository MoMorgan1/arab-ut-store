<?php

use App\Enums\UserRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

test('the Admin MFA page exposes only safe booleans and relative endpoint URLs', function (
    string $locale,
    string $path,
): void {
    $user = adminMfaUser(UserRole::Staff, confirmed: false, locale: $locale);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get($path);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/security/mfa')
            ->where('locale', $locale)
            ->where('direction', $locale === 'en' ? 'ltr' : 'rtl')
            ->where('auth', null)
            ->where('mfa.passwordConfigured', true)
            ->where('mfa.enabled', true)
            ->where('mfa.confirmed', false)
            ->where('mfa.routes.enable', '/user/two-factor-authentication')
            ->where('mfa.routes.confirm', '/user/confirmed-two-factor-authentication')
            ->where('mfa.routes.qrCode', '/user/two-factor-qr-code')
            ->where('mfa.routes.recoveryCodes', '/user/two-factor-recovery-codes')
            ->where('mfa.routes.regenerateRecoveryCodes', '/user/two-factor-recovery-codes')
            ->where('mfa.routes.disable', '/user/two-factor-authentication'));

    expect($response->getContent())
        ->not->toContain(
            $user->email,
            'ADMINMFASECRET',
            'recovery-code-one',
            'two_factor_secret',
            'two_factor_recovery_codes',
            (string) $user->getRawOriginal('password'),
        );
})->with([
    'Arabic' => ['ar', '/admin/security/mfa'],
    'English' => ['en', '/en/admin/security/mfa'],
]);

test('the MFA page requires a recent password confirmation', function (): void {
    $staff = adminMfaUser(UserRole::Staff, confirmed: false);

    $this->actingAs($staff)
        ->get('/admin/security/mfa')
        ->assertRedirect(route('password.confirm'));
});

test('password-confirmed Admin and Staff can render private MFA management', function (UserRole $role): void {
    $user = adminMfaUser($role, confirmed: true);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get('/admin/security/mfa')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
})->with([
    'Admin' => [UserRole::Admin],
    'Staff' => [UserRole::Staff],
]);

test('Fortify QR JSON requires password confirmation and is always private', function (): void {
    $staff = adminMfaUser(UserRole::Staff, confirmed: false);

    $this->actingAs($staff)
        ->getJson(route('two-factor.qr-code'))
        ->assertStatus(423);

    $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->getJson(route('two-factor.qr-code'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonStructure(['svg', 'url']);
});

test('Fortify recovery code JSON requires password confirmation and is always private', function (): void {
    $staff = adminMfaUser(UserRole::Staff, confirmed: true);

    $this->actingAs($staff)
        ->getJson(route('two-factor.recovery-codes'))
        ->assertStatus(423);

    $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->getJson(route('two-factor.recovery-codes'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['recovery-code-one', 'recovery-code-two']);
});

function adminMfaUser(UserRole $role, bool $confirmed, string $locale = 'ar'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
        'preferred_locale' => $locale,
    ]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINMFASECRET'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([
            'recovery-code-one',
            'recovery-code-two',
        ], JSON_THROW_ON_ERROR)),
        'two_factor_confirmed_at' => $confirmed ? now() : null,
    ])->save();

    return $user;
}
