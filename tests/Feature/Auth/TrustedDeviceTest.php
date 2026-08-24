<?php

use App\Auth\TrustedDeviceRegistry;
use App\Enums\UserRole;
use App\Models\StaffAuditLog;
use App\Models\TwoFactorTrustedDevice;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

/** @return array{user: User, secret: string} */
function trustedDeviceUser(UserRole $role = UserRole::Admin): array
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
    ]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([
            'recovery-code-one',
        ], JSON_THROW_ON_ERROR)),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return compact('user', 'secret');
}

/**
 * Encrypts a token the way the cookie middleware would, so the request under
 * test carries a cookie the application will actually accept.
 */
function trustedDeviceCookieValue(string $token): string
{
    $prefix = CookieValuePrefix::create(
        TrustedDeviceRegistry::COOKIE,
        app('encrypter')->getKey(),
    );

    return encrypt($prefix.$token, false);
}

/** Mints a device for the user and returns the plaintext cookie token. */
function issueTrustedDevice(User $user): string
{
    return (string) app(TrustedDeviceRegistry::class)
        ->remember($user, request())
        ->getValue();
}

it('challenges a browser that has never passed two factor', function (): void {
    ['user' => $user] = trustedDeviceUser();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'SecurePassword!12',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

it('issues a trusted device once the challenge is passed', function (): void {
    ['user' => $user, 'secret' => $secret] = trustedDeviceUser();

    $response = $this->withSession(['login.id' => $user->id])
        ->post(route('two-factor.login.store'), [
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ]);

    $response->assertCookie(TrustedDeviceRegistry::COOKIE);
    expect($user->trustedDevices()->count())->toBe(1);

    $device = $user->trustedDevices()->firstOrFail();

    // Only the digest is persisted, so a dump of the table is not replayable.
    expect($device->token_hash)->toHaveLength(64)
        ->and($device->expires_at->greaterThan(now()->addDays(29)))->toBeTrue()
        ->and($device->expires_at->lessThan(now()->addDays(31)))->toBeTrue();
});

it('issues a trusted device when a recovery code is used', function (): void {
    ['user' => $user] = trustedDeviceUser();

    $this->withSession(['login.id' => $user->id])
        ->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one'])
        ->assertCookie(TrustedDeviceRegistry::COOKIE);

    expect($user->trustedDevices()->count())->toBe(1);
});

it('skips the challenge on a later login from the same browser', function (): void {
    ['user' => $user] = trustedDeviceUser();
    $token = issueTrustedDevice($user);

    // The reported complaint: signing out and back in re-challenged every time.
    $this->withUnencryptedCookie(
        TrustedDeviceRegistry::COOKIE,
        trustedDeviceCookieValue($token),
    )->post('/login', [
        'email' => $user->email,
        'password' => 'SecurePassword!12',
    ])->assertRedirect(route('admin.overview'));

    $this->assertAuthenticatedAs($user);
});

it('never lets one account trusted device satisfy another account challenge', function (): void {
    ['user' => $user] = trustedDeviceUser();
    ['user' => $other] = trustedDeviceUser();
    $token = issueTrustedDevice($user);

    $this->withUnencryptedCookie(
        TrustedDeviceRegistry::COOKIE,
        trustedDeviceCookieValue($token),
    )->post('/login', [
        'email' => $other->email,
        'password' => 'SecurePassword!12',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

it('challenges again once the device has expired', function (): void {
    ['user' => $user] = trustedDeviceUser();
    $token = issueTrustedDevice($user);

    $user->trustedDevices()->update(['expires_at' => now()->subMinute()]);

    $this->withUnencryptedCookie(
        TrustedDeviceRegistry::COOKIE,
        trustedDeviceCookieValue($token),
    )->post('/login', [
        'email' => $user->email,
        'password' => 'SecurePassword!12',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

it('rejects a forged cookie value', function (): void {
    ['user' => $user] = trustedDeviceUser();
    issueTrustedDevice($user);

    $this->withUnencryptedCookie(
        TrustedDeviceRegistry::COOKIE,
        trustedDeviceCookieValue(str_repeat('a', 64)),
    )->post('/login', [
        'email' => $user->email,
        'password' => 'SecurePassword!12',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

it('still refuses a wrong password on a trusted device', function (): void {
    ['user' => $user] = trustedDeviceUser();
    $token = issueTrustedDevice($user);

    // Trust replaces the second factor, never the first.
    $this->from('/login')->withUnencryptedCookie(
        TrustedDeviceRegistry::COOKIE,
        trustedDeviceCookieValue($token),
    )->post('/login', [
        'email' => $user->email,
        'password' => 'not-the-password',
    ])->assertRedirect('/login');

    $this->assertGuest();
});

it('does not renew the window every time the device is used', function (): void {
    ['user' => $user] = trustedDeviceUser();
    $token = issueTrustedDevice($user);
    $originalExpiry = $user->trustedDevices()->firstOrFail()->expires_at;

    $this->travel(5)->days();

    $this->withUnencryptedCookie(
        TrustedDeviceRegistry::COOKIE,
        trustedDeviceCookieValue($token),
    )->post('/login', [
        'email' => $user->email,
        'password' => 'SecurePassword!12',
    ]);

    // 30 days from the challenge, not a window that renews itself forever.
    $device = $user->trustedDevices()->firstOrFail();

    expect($device->expires_at->equalTo($originalExpiry))->toBeTrue()
        ->and($device->last_used_at->greaterThan($originalExpiry->subDays(28)))->toBeTrue();
});

it('drops every trusted device when two factor is disabled', function (): void {
    ['user' => $user] = trustedDeviceUser();
    issueTrustedDevice($user);

    TwoFactorAuthenticationDisabled::dispatch($user);

    expect($user->trustedDevices()->count())->toBe(0);
});

it('drops every trusted device when recovery codes are regenerated', function (): void {
    ['user' => $user] = trustedDeviceUser();
    issueTrustedDevice($user);

    RecoveryCodesGenerated::dispatch($user);

    expect($user->trustedDevices()->count())->toBe(0);
});

it('drops trusted devices with the user row', function (): void {
    ['user' => $user] = trustedDeviceUser();
    issueTrustedDevice($user);

    $user->delete();

    expect(TwoFactorTrustedDevice::query()->count())->toBe(0);
});

it('prunes expired rows and counts only live ones', function (): void {
    ['user' => $user] = trustedDeviceUser();
    $registry = app(TrustedDeviceRegistry::class);
    issueTrustedDevice($user);
    issueTrustedDevice($user);

    $user->trustedDevices()->limit(1)->update(['expires_at' => now()->subDay()]);

    expect($registry->activeCount($user))->toBe(1)
        ->and($registry->prune())->toBe(1)
        ->and($user->trustedDevices()->count())->toBe(1);
});

it('forgets every device on request', function (): void {
    ['user' => $user] = trustedDeviceUser();
    $registry = app(TrustedDeviceRegistry::class);
    issueTrustedDevice($user);
    issueTrustedDevice($user);

    expect($registry->forgetAll($user))->toBe(2)
        ->and($user->trustedDevices()->count())->toBe(0);
});

it('revokes every trusted device from settings and audits it', function (): void {
    ['user' => $user] = trustedDeviceUser();
    issueTrustedDevice($user);
    issueTrustedDevice($user);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('admin.security.trusted-devices.destroy'))
        ->assertOk()
        ->assertJson(['revoked' => 2]);

    expect($user->trustedDevices()->count())->toBe(0);

    $audit = StaffAuditLog::query()->latest('id')->firstOrFail();

    expect($audit->action)->toBe('security.trusted_devices_revoked')
        ->and($audit->metadata['revoked_count'])->toBe(2);
});

it('revokes only the requesting account devices', function (): void {
    ['user' => $user] = trustedDeviceUser();
    ['user' => $other] = trustedDeviceUser();
    issueTrustedDevice($user);
    issueTrustedDevice($other);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('admin.security.trusted-devices.destroy'))
        ->assertOk();

    expect($user->trustedDevices()->count())->toBe(0)
        ->and($other->trustedDevices()->count())->toBe(1);
});
