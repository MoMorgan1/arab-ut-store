<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticatable;
use PragmaRX\Google2FA\Google2FA;

test('users support encrypted Fortify TOTP state without serializing secrets', function () {
    $user = User::factory()->create();

    expect(Schema::hasColumns('users', [
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ]))->toBeTrue()
        ->and(class_uses_recursive($user))->toContain(TwoFactorAuthenticatable::class)
        ->and(array_keys($user->toArray()))->not->toContain(
            'two_factor_secret',
            'two_factor_recovery_codes',
        );
});

test('the Fortify trait distinguishes unconfirmed and confirmed TOTP state', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('unconfirmed-secret'),
    ]);

    expect($user->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $user->forceFill(['two_factor_confirmed_at' => now()]);

    expect($user->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('confirmed staff password login requires a TOTP challenge', function () {
    ['user' => $staff] = confirmedTotpUser(UserRole::Staff);

    $this->post('/login', [
        'email' => $staff->email,
        'password' => 'SecurePassword!12',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($staff->id);
});

test('a valid TOTP code completes the challenged session', function () {
    ['user' => $staff, 'secret' => $secret] = confirmedTotpUser(UserRole::Staff);
    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->withSession(['login.id' => $staff->id])
        ->post(route('two-factor.login.store'), ['code' => $code])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($staff);
    expect(session()->has('login.id'))->toBeFalse();
});

test('an unused recovery code completes the challenged session and cannot be replayed', function () {
    ['user' => $staff] = confirmedTotpUser(UserRole::Staff);

    $this->withSession(['login.id' => $staff->id])
        ->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($staff);
    expect($staff->fresh()->recoveryCodes())->not->toContain('recovery-code-one');

    auth()->logout();

    $this->withSession(['login.id' => $staff->id])
        ->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one'])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

test('privileged users without confirmed TOTP enter only the constrained normal session', function (
    UserRole $role,
    string $totpState,
) {
    $user = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
    ]);

    if ($totpState === 'unconfirmed') {
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('unconfirmed-secret'),
        ])->save();
    }

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'SecurePassword!12',
    ])->assertRedirect('/my-account');

    $this->assertAuthenticatedAs($user);
    expect(session()->has('login.id'))->toBeFalse();
})->with([
    'Admin without TOTP' => [UserRole::Admin, 'missing'],
    'Staff without TOTP' => [UserRole::Staff, 'missing'],
    'Admin with unconfirmed TOTP' => [UserRole::Admin, 'unconfirmed'],
    'Staff with unconfirmed TOTP' => [UserRole::Staff, 'unconfirmed'],
]);

test('ineligible pending privileged challenges clear the session on both challenge routes', function (
    UserRole $role,
    string $eligibilityChange,
    string $method,
) {
    ['user' => $staff] = confirmedTotpUser($role);

    match ($eligibilityChange) {
        'deactivated' => $staff->forceFill(['is_active' => false])->save(),
        'password_removed' => $staff->forceFill(['password' => null])->save(),
        'totp_unconfirmed' => $staff->forceFill(['two_factor_confirmed_at' => null])->save(),
    };

    $request = $this->withSession([
        'login.id' => $staff->id,
        'login.remember' => true,
    ]);
    $response = $method === 'GET'
        ? $request->get(route('two-factor.login'))
        : $request->post(route('two-factor.login.store'), ['code' => '000000']);

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors([
            'email' => trans('auth_ui.two_factor_challenge.invalid_code', [], 'ar'),
        ]);
    expect(session()->has('login.id'))->toBeFalse()
        ->and(session()->has('login.remember'))->toBeFalse();
})->with([
    'Admin deactivated GET' => [UserRole::Admin, 'deactivated', 'GET'],
    'Staff deactivated POST' => [UserRole::Staff, 'deactivated', 'POST'],
    'Admin password removed POST' => [UserRole::Admin, 'password_removed', 'POST'],
    'Staff password removed GET' => [UserRole::Staff, 'password_removed', 'GET'],
    'Admin TOTP unconfirmed GET' => [UserRole::Admin, 'totp_unconfirmed', 'GET'],
    'Staff TOTP unconfirmed POST' => [UserRole::Staff, 'totp_unconfirmed', 'POST'],
]);

test('failed TOTP challenges use the pending users locale and mode-specific copy', function (
    string $locale,
    string $field,
    string $translation,
) {
    ['user' => $staff] = confirmedTotpUser(UserRole::Staff);
    $staff->update(['preferred_locale' => $locale]);

    $this->withSession(['login.id' => $staff->id])
        ->post(route('two-factor.login.store'), [$field => 'invalid-code'])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors([
            $field => trans("auth_ui.two_factor_challenge.{$translation}", [], $locale),
        ]);
})->with([
    'Arabic authenticator code' => ['ar', 'code', 'invalid_code'],
    'English authenticator code' => ['en', 'code', 'invalid_code'],
    'Arabic recovery code' => ['ar', 'recovery_code', 'invalid_recovery_code'],
    'English recovery code' => ['en', 'recovery_code', 'invalid_recovery_code'],
]);

test('the challenge page follows the privileged users preferred locale', function (
    string $locale,
    string $direction,
) {
    ['user' => $staff] = confirmedTotpUser(UserRole::Staff);
    $staff->update(['preferred_locale' => $locale]);

    $this->withSession(['login.id' => $staff->id])
        ->get(route('two-factor.login'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/two-factor-challenge')
            ->where('locale', $locale)
            ->where('direction', $direction));
})->with([
    'Arabic' => ['ar', 'rtl'],
    'English' => ['en', 'ltr'],
]);

/** @return array{user: User, secret: string} */
function confirmedTotpUser(UserRole $role): array
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
