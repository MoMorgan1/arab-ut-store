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

test('a valid TOTP or unused recovery code completes the challenged session', function () {
    ['user' => $staff, 'secret' => $secret] = confirmedTotpUser(UserRole::Staff);
    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->withSession(['login.id' => $staff->id])
        ->post(route('two-factor.login.store'), ['code' => $code])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($staff);
    expect(session()->has('login.id'))->toBeFalse();
});

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
