<?php

use App\Enums\UserRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

test('the Admin settings page exposes only safe booleans and relative endpoint URLs', function (
    string $locale,
    string $path,
): void {
    $user = adminMfaUser(UserRole::Staff, confirmed: false, locale: $locale);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get($path);
    $secret = Fortify::currentEncrypter()->decrypt(
        (string) $user->two_factor_secret,
    );

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/settings')
            ->where('locale', str_starts_with($path, '/en/') ? 'en' : 'ar')
            ->where('direction', str_starts_with($path, '/en/') ? 'ltr' : 'rtl')
            ->where('auth', null)
            ->where('mfa.passwordConfigured', true)
            ->where('mfa.enabled', true)
            ->where('mfa.confirmed', false)
            ->where('mfa.routes.enable', '/user/two-factor-authentication')
            ->where('mfa.routes.confirm', '/user/confirmed-two-factor-authentication')
            ->where('mfa.routes.qrCode', '/user/two-factor-qr-code')
            ->where('mfa.routes.recoveryCodes', '/user/two-factor-recovery-codes')
            ->where('mfa.routes.regenerateRecoveryCodes', '/user/two-factor-recovery-codes')
            ->where('mfa.routes.disable', '/user/two-factor-authentication')
            ->where('team', null)
            ->where('teamUrls', null));

    expect($response->getContent())
        ->not->toContain(
            $secret,
            'recovery-code-one',
            'two_factor_secret',
            'two_factor_recovery_codes',
            (string) $user->getRawOriginal('password'),
        );
})->with([
    'Canonical' => ['en', '/admin/settings'],
    'English alias' => ['en', '/en/admin/settings'],
]);

test('password-confirmed Admin and Staff can render private settings page', function (UserRole $role): void {
    $user = adminMfaUser($role, confirmed: true);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get('/admin/settings')
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

test('inactive privileged users cannot use any Fortify MFA management endpoint', function (
    UserRole $role,
    string $method,
    string $routeName,
): void {
    $user = adminMfaUser($role, confirmed: true);
    $user->forceFill(['is_active' => false])->save();

    $request = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $response = match ($method) {
        'GET' => $request->getJson(route($routeName)),
        'POST' => $request->postJson(route($routeName), ['code' => '000000']),
        'DELETE' => $request->deleteJson(route($routeName)),
    };

    $response->assertForbidden();
})->with(function (): array {
    $endpoints = [
        'enable' => ['POST', 'two-factor.enable'],
        'confirm' => ['POST', 'two-factor.confirm'],
        'disable' => ['DELETE', 'two-factor.disable'],
        'QR code' => ['GET', 'two-factor.qr-code'],
        'secret key' => ['GET', 'two-factor.secret-key'],
        'recovery codes' => ['GET', 'two-factor.recovery-codes'],
        'regenerate recovery codes' => ['POST', 'two-factor.regenerate-recovery-codes'],
    ];
    $cases = [];

    foreach ([UserRole::Admin, UserRole::Staff] as $role) {
        foreach ($endpoints as $label => [$method, $routeName]) {
            $cases["inactive {$role->value} {$label}"] = [$role, $method, $routeName];
        }
    }

    return $cases;
});

test('active Customers retain Fortify MFA management access', function (): void {
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    $this->actingAs($customer)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson(route('two-factor.enable'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($customer->fresh()->two_factor_secret)->toBeString();
});

function adminMfaUser(UserRole $role, bool $confirmed, string $locale = 'en'): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
        'preferred_locale' => $locale,
    ]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([
            'recovery-code-one',
            'recovery-code-two',
        ], JSON_THROW_ON_ERROR)),
        'two_factor_confirmed_at' => $confirmed ? now() : null,
    ])->save();

    return $user;
}
