<?php

use App\Auth\TrustedDeviceRegistry;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/** @return array{user: User, secret: string} */
function adminMfaGateUser(UserRole $role = UserRole::Admin): array
{
    $secret = (new Google2FA)->generateSecretKey(16);
    $user = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
        'preferred_locale' => 'en',
    ]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return compact('user', 'secret');
}

/**
 * Encrypts a token the way the cookie middleware would, so the request under
 * test carries a cookie the application will actually accept.
 */
function adminMfaGateCookieValue(string $token): string
{
    $prefix = CookieValuePrefix::create(
        TrustedDeviceRegistry::COOKIE,
        app('encrypter')->getKey(),
    );

    return encrypt($prefix.$token, false);
}

/** Mints a device for the user and returns the plaintext cookie token. */
function issueAdminMfaGateDevice(User $user): string
{
    return (string) app(TrustedDeviceRegistry::class)
        ->remember($user, request())
        ->getValue();
}

/**
 * Fortify's provider refuses to verify the same code twice (it caches the
 * verified step keyed by the code), so a challenge moments after a Fortify
 * confirmation must use the next step's code, as a real authenticator would.
 */
function adminMfaGateNextOtp(string $secret): string
{
    $engine = new Google2FA;

    return (string) $engine->oathTotp($secret, $engine->getTimestamp() + 1);
}

it('accepts a valid trusted device without a session marker', function (): void {
    ['user' => $admin] = adminMfaGateUser();
    $token = issueAdminMfaGateDevice($admin);

    $this->actingAs($admin)
        ->withoutTwoFactorSession()
        ->withUnencryptedCookie(TrustedDeviceRegistry::COOKIE, adminMfaGateCookieValue($token))
        ->get('/admin')
        ->assertOk();
});

it('challenges a browser whose device was revoked and whose device grant is older than the recheck window', function (): void {
    ['user' => $admin] = adminMfaGateUser();
    $token = issueAdminMfaGateDevice($admin);
    app(TrustedDeviceRegistry::class)->forgetAll($admin);

    $this->actingAs($admin)
        ->withSession([
            'auth.two_factor_confirmed_at' => now()->subMinutes(11)->timestamp,
            'auth.two_factor_confirmed_via' => 'device',
        ])
        ->withUnencryptedCookie(TrustedDeviceRegistry::COOKIE, adminMfaGateCookieValue($token))
        ->get('/admin')
        ->assertRedirect('/admin/confirm-2fa');
});

it('trusts a device grant from one minute ago without re-reading the device row', function (): void {
    ['user' => $admin] = adminMfaGateUser();

    expect($admin->trustedDevices()->count())->toBe(0);

    $this->actingAs($admin)
        ->withSession([
            'auth.two_factor_confirmed_at' => now()->subMinute()->timestamp,
            'auth.two_factor_confirmed_via' => 'device',
        ])
        ->get('/admin')
        ->assertOk();
});

it('refuses an expired device row even with a matching cookie', function (): void {
    ['user' => $admin] = adminMfaGateUser();
    $token = issueAdminMfaGateDevice($admin);

    $admin->trustedDevices()->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($admin)
        ->withoutTwoFactorSession()
        ->withUnencryptedCookie(TrustedDeviceRegistry::COOKIE, adminMfaGateCookieValue($token))
        ->get('/admin')
        ->assertRedirect('/admin/confirm-2fa');
});

it('refuses a device cookie minted for another user', function (): void {
    ['user' => $owner] = adminMfaGateUser();
    ['user' => $other] = adminMfaGateUser();
    $token = issueAdminMfaGateDevice($owner);

    $this->actingAs($other)
        ->withoutTwoFactorSession()
        ->withUnencryptedCookie(TrustedDeviceRegistry::COOKIE, adminMfaGateCookieValue($token))
        ->get('/admin')
        ->assertRedirect('/admin/confirm-2fa');
});

it('challenges a browser with neither a cookie nor a marker', function (): void {
    ['user' => $admin] = adminMfaGateUser();

    $this->actingAs($admin)
        ->withoutTwoFactorSession()
        ->get('/admin')
        ->assertRedirect('/admin/confirm-2fa');
});

it('honors a challenge grant for thirty days and refuses an older one', function (): void {
    ['user' => $admin] = adminMfaGateUser();

    $this->actingAs($admin)
        ->withSession(['auth.two_factor_confirmed_at' => now()->subDays(29)->timestamp])
        ->get('/admin')
        ->assertOk();

    $this->actingAs($admin)
        ->withSession(['auth.two_factor_confirmed_at' => now()->subDays(31)->timestamp])
        ->get('/admin')
        ->assertRedirect('/admin/confirm-2fa');
});

it('challenges the acting browser on its next request after it revokes every device', function (): void {
    ['user' => $admin] = adminMfaGateUser();
    issueAdminMfaGateDevice($admin);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('admin.security.trusted-devices.destroy'))
        ->assertOk();

    $this->get('/admin')->assertRedirect('/admin/confirm-2fa');
});

it('mints a trusted device when two factor is confirmed on the admin screen', function (): void {
    ['user' => $admin, 'secret' => $secret] = adminMfaGateUser();

    $this->actingAs($admin)->withoutTwoFactorSession();

    $response = $this->post('/admin/confirm-2fa', [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ]);

    $response->assertRedirect('/admin')
        ->assertCookie(TrustedDeviceRegistry::COOKIE);

    expect($admin->trustedDevices()->count())->toBe(1);
});

it('invalidates another session marker when the password is reset', function (): void {
    ['user' => $admin] = adminMfaGateUser();
    $stampedAt = now()->subMinute()->getTimestamp();

    $this->actingAs($admin)->withSession([
        'auth.two_factor_confirmed_at' => $stampedAt,
        'auth.two_factor_confirmed_via' => 'challenge',
        'auth.two_factor_revocation' => 0,
    ]);

    // The reset happens in another browser: this request carries no session,
    // so only the account counter can invalidate the marker under test.
    $this->app->instance('request', Request::create('/__tests/other-browser'));

    event(new PasswordReset($admin));

    expect(session('auth.two_factor_confirmed_at'))->toBe($stampedAt)
        ->and($admin->fresh()->mfa_revocation)->toBe(1);

    $this->get('/admin')->assertRedirect('/admin/confirm-2fa');
});

it('invalidates another session marker when two factor authentication is disabled', function (): void {
    ['user' => $admin] = adminMfaGateUser();
    $stampedAt = now()->subMinute()->getTimestamp();

    $this->actingAs($admin)->withSession([
        'auth.two_factor_confirmed_at' => $stampedAt,
        'auth.two_factor_confirmed_via' => 'challenge',
        'auth.two_factor_revocation' => 0,
    ]);

    $this->app->instance('request', Request::create('/__tests/other-browser'));

    event(new TwoFactorAuthenticationDisabled($admin));

    expect(session('auth.two_factor_confirmed_at'))->toBe($stampedAt)
        ->and($admin->fresh()->mfa_revocation)->toBe(1);

    $this->get('/admin')->assertRedirect('/admin/confirm-2fa');
});

it('invalidates another session marker minted in the same second as the revocation', function (): void {
    $this->freezeTime();

    ['user' => $admin] = adminMfaGateUser();

    $this->actingAs($admin)->withSession([
        'auth.two_factor_confirmed_at' => now()->getTimestamp(),
        'auth.two_factor_confirmed_via' => 'challenge',
        'auth.two_factor_revocation' => 0,
    ]);

    $this->app->instance('request', Request::create('/__tests/other-browser'));

    // A timestamp epoch compared with a strict less-than let a marker that
    // shared the revocation's second survive it; the counter has no such gap.
    event(new PasswordReset($admin));

    expect($admin->fresh()->mfa_revocation)->toBe(1);

    $this->get('/admin')->assertRedirect('/admin/confirm-2fa');
});

it('invalidates another session marker when the clock moves backwards before the revocation', function (): void {
    ['user' => $admin] = adminMfaGateUser();

    $this->actingAs($admin)->withSession([
        'auth.two_factor_confirmed_at' => now()->getTimestamp(),
        'auth.two_factor_confirmed_via' => 'challenge',
        'auth.two_factor_revocation' => 0,
    ]);

    $this->app->instance('request', Request::create('/__tests/other-browser'));

    // An NTP correction or a restored VM snapshot can put the clock behind a
    // marker minted moments earlier; the counter does not read the clock.
    $this->travelTo(now()->subMinutes(5));

    event(new PasswordReset($admin));

    expect($admin->fresh()->mfa_revocation)->toBe(1);

    $this->get('/admin')->assertRedirect('/admin/confirm-2fa');
});

it('counts two revocation events in the same request cycle', function (): void {
    ['user' => $admin] = adminMfaGateUser();

    $this->app->instance('request', Request::create('/__tests/other-browser'));

    event(new PasswordReset($admin));
    event(new TwoFactorAuthenticationDisabled($admin));

    expect($admin->fresh()->mfa_revocation)->toBe(2);
});

it('treats a marker without a counter as valid only while the account has no revocations', function (): void {
    ['user' => $admin] = adminMfaGateUser();

    // tests/TestCase.php seeds exactly this bare marker for every admin test
    // browser, so it must stand while the account has no revocation history.
    $this->actingAs($admin)
        ->withSession(['auth.two_factor_confirmed_at' => now()->getTimestamp()])
        ->get('/admin')
        ->assertOk();

    $this->app->instance('request', Request::create('/__tests/other-browser'));

    event(new PasswordReset($admin));

    $this->get('/admin')->assertRedirect('/admin/confirm-2fa');
});

it('keeps admin access after confirming two factor on the admin screen', function (): void {
    ['user' => $admin, 'secret' => $secret] = adminMfaGateUser();

    $this->actingAs($admin)->withoutTwoFactorSession();

    $this->post('/admin/confirm-2fa', [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertRedirect('/admin');

    // The gate compares the recorded counter, so a marker that satisfied the
    // age check without recording one would still be refused here.
    expect(session('auth.two_factor_revocation'))->toBe(0);

    $this->get('/admin')->assertOk();
});

it('keeps the admin gate reachable after confirming two factor through fortify', function (): void {
    ['user' => $admin] = adminMfaGateUser();
    $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);

    $this->postJson(route('two-factor.enable'))->assertOk();

    $secret = (string) Fortify::currentEncrypter()->decrypt(
        (string) $admin->fresh()->two_factor_secret,
    );

    // Confirming dispatches TwoFactorAuthenticationConfirmed, which bumps the
    // counter: a marker minted afterwards must record the new value to satisfy
    // the admin gate.
    $this->postJson(route('two-factor.confirm'), [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertOk();

    expect($admin->fresh()->mfa_revocation)->toBe(1);

    $this->get('/admin')->assertRedirect('/admin/confirm-2fa');

    $this->post('/admin/confirm-2fa', [
        'code' => adminMfaGateNextOtp($secret),
    ])->assertRedirect('/admin');

    expect(session('auth.two_factor_revocation'))->toBe(1);

    $this->get('/admin')->assertOk();
});

it('bumps the revocation counter without an active request session', function (): void {
    ['user' => $admin] = adminMfaGateUser();

    $this->app->forgetInstance('request');

    event(new RecoveryCodesGenerated($admin));

    expect($admin->fresh()->mfa_revocation)->toBe(1);
});
