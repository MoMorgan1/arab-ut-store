<?php

use App\Enums\UserRole;
use App\Models\PhoneVerification;
use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    config()->set('services.google.client_id', 'google-client');
    config()->set('services.google.client_secret', 'google-secret');
    config()->set('services.google.redirect', 'https://store.example.test/auth/google/callback');

    config()->set('services.whapi', [
        'base_url' => 'https://gate.whapi.test',
        'token' => 'synthetic-whapi-token',
    ]);
});

test('a privileged user logged in via Google is redirected to confirm-2fa on admin and reaches admin after submitting valid code', function (UserRole $role): void {
    $rawSecret = (new Google2FA)->generateSecretKey(16);
    $user = User::factory()->create([
        'email' => 'admin-google@example.test',
        'role' => $role,
        'preferred_locale' => 'en',
    ]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($rawSecret),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([
            'test-recovery-code-1',
            'test-recovery-code-2',
        ])),
    ])->save();

    $socialAccount = SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-admin-123',
        'provider_email' => $user->email,
    ]);

    $providerUser = (new SocialiteUser)->map([
        'id' => 'google-admin-123',
        'name' => $user->name,
        'email' => $user->email,
        'email_verified' => true,
    ])->setRaw(['email_verified' => true]);

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn($providerUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

    // 1. Log in via Google
    $this->get('/auth/google/callback');
    $this->assertAuthenticatedAs($user);
    expect(session()->has('auth.two_factor_confirmed_at'))->toBeFalse();

    // 2. Access /admin -> redirected to confirm-2fa
    $response = $this->get('/admin');
    $response->assertRedirect('/admin/confirm-2fa');

    // 3. Confirm screen renders correctly
    $this->get('/admin/confirm-2fa')->assertOk();

    // 4. Submit valid TOTP code
    $validCode = (new Google2FA)->getCurrentOtp($rawSecret);
    $confirmResponse = $this->post('/admin/confirm-2fa', [
        'code' => $validCode,
    ]);

    // 5. Redirected to intended /admin and session is stamped
    $confirmResponse->assertRedirect('/admin');
    expect(session()->has('auth.two_factor_confirmed_at'))->toBeTrue();

    // 6. Access /admin successfully
    $this->get('/admin')->assertOk();
})->with([
    'Admin' => [UserRole::Admin],
    'Staff' => [UserRole::Staff],
]);

test('a privileged user logged in via WhatsApp is redirected to confirm-2fa on admin and reaches admin after submitting valid code', function (UserRole $role): void {
    $rawSecret = (new Google2FA)->generateSecretKey(16);
    $user = User::factory()->create([
        'phone' => '+201009876543',
        'phone_verified_at' => now(),
        'role' => $role,
        'preferred_locale' => 'en',
    ]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($rawSecret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    PhoneVerification::create([
        'user_id' => $user->id,
        'phone' => '+201009876543',
        'code_hash' => Hash::make('654321'),
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10),
    ]);

    // 1. Log in via WhatsApp
    $verifyResponse = $this->postJson(route('auth.whatsapp.verify'), [
        'phone' => '+201009876543',
        'code' => '654321',
    ]);
    $verifyResponse->assertOk();
    $this->assertAuthenticatedAs($user);
    expect(session()->has('auth.two_factor_confirmed_at'))->toBeFalse();

    // 2. Access /admin -> redirected to confirm-2fa
    $this->get('/admin')->assertRedirect('/admin/confirm-2fa');

    // 3. Submit valid TOTP code
    $validCode = (new Google2FA)->getCurrentOtp($rawSecret);
    $confirmResponse = $this->post('/admin/confirm-2fa', [
        'code' => $validCode,
    ]);
    $confirmResponse->assertRedirect('/admin');
    expect(session()->has('auth.two_factor_confirmed_at'))->toBeTrue();

    // 4. Access /admin successfully
    $this->get('/admin')->assertOk();
})->with([
    'Admin' => [UserRole::Admin],
    'Staff' => [UserRole::Staff],
]);

test('after confirming once a second and third admin request do not re prompt', function (): void {
    $rawSecret = (new Google2FA)->generateSecretKey(16);
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
        'preferred_locale' => 'en',
    ]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($rawSecret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    // Start with session where user is authenticated but two_factor_confirmed_at is not set
    $this->actingAs($admin)
        ->withoutTwoFactorSession() // explicitly empty session
        ->get('/admin')
        ->assertRedirect('/admin/confirm-2fa');

    // Submit valid code to confirm session
    $validCode = (new Google2FA)->getCurrentOtp($rawSecret);
    $this->post('/admin/confirm-2fa', ['code' => $validCode])
        ->assertRedirect('/admin');

    // First request: succeeds without redirect to confirm-2fa
    $this->get('/admin')
        ->assertOk()
        ->assertDontSee('/admin/confirm-2fa');

    // Second request: succeeds without redirect to confirm-2fa
    $this->get('/admin')
        ->assertOk()
        ->assertDontSee('/admin/confirm-2fa');

    // Third request: succeeds without redirect to confirm-2fa
    $this->get('/admin')
        ->assertOk()
        ->assertDontSee('/admin/confirm-2fa');
});

test('a plain Customer is never sent to the confirm screen', function (): void {
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
    ]);

    // Customer trying to access /admin gets 403 Forbidden directly, never redirected to confirm-2fa
    $this->actingAs($customer)
        ->get('/admin')
        ->assertForbidden();

    // Customer accessing /admin/confirm-2fa directly also gets 403 Forbidden
    $this->actingAs($customer)
        ->get('/admin/confirm-2fa')
        ->assertForbidden();

    $this->actingAs($customer)
        ->post('/admin/confirm-2fa', ['code' => '123456'])
        ->assertForbidden();
});

test('a privileged user with no 2FA configured lands on admin settings', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
        'preferred_locale' => 'en',
    ]);
    $admin->forceFill([
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ])->save();

    // Accessing /admin redirects to settings, not confirm-2fa
    $this->actingAs($admin)
        ->withoutTwoFactorSession()
        ->get('/admin')
        ->assertRedirect('/admin/settings');

    // Accessing confirm-2fa directly also redirects to settings
    $this->actingAs($admin)
        ->withoutTwoFactorSession()
        ->get('/admin/confirm-2fa')
        ->assertRedirect('/admin/settings');
});

test('an invalid code is rejected and does not stamp the marker', function (): void {
    $rawSecret = (new Google2FA)->generateSecretKey(16);
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
        'preferred_locale' => 'en',
    ]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($rawSecret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($admin)->withoutTwoFactorSession();

    $response = $this->post('/admin/confirm-2fa', [
        'code' => '000000',
    ]);

    $response->assertSessionHasErrors('code');
    expect(session()->has('auth.two_factor_confirmed_at'))->toBeFalse();

    // Still blocked on /admin
    $this->get('/admin')->assertRedirect('/admin/confirm-2fa');
});

test('a valid recovery code is accepted consumes the code and stamps the marker', function (): void {
    $rawSecret = (new Google2FA)->generateSecretKey(16);
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
        'preferred_locale' => 'en',
    ]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($rawSecret),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([
            'valid-recovery-code-123',
            'valid-recovery-code-456',
        ])),
    ])->save();

    $this->actingAs($admin)->withoutTwoFactorSession();

    $response = $this->post('/admin/confirm-2fa', [
        'recovery_code' => 'valid-recovery-code-123',
    ]);

    $response->assertRedirect('/admin');
    expect(session()->has('auth.two_factor_confirmed_at'))->toBeTrue();

    // The recovery code should be replaced/consumed
    $admin->refresh();
    expect($admin->recoveryCodes())->not->toContain('valid-recovery-code-123');
    expect(count($admin->recoveryCodes()))->toBe(2);

    // Can now access /admin
    $this->get('/admin')->assertOk();
});

test('visiting confirm-2fa when already confirmed in session redirects to overview', function (): void {
    $rawSecret = (new Google2FA)->generateSecretKey(16);
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
        'preferred_locale' => 'en',
    ]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($rawSecret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($admin)
        ->withSession(['auth.two_factor_confirmed_at' => now()->timestamp])
        ->get('/admin/confirm-2fa')
        ->assertRedirect('/admin');
});

test('visiting confirm-2fa directly when unconfirmed renders the confirm screen and does not redirect to itself', function (): void {
    $rawSecret = (new Google2FA)->generateSecretKey(16);
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
        'preferred_locale' => 'en',
    ]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($rawSecret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    // Confirm route itself is not blocked by EnsureAdminMfa (no infinite loop)
    $this->actingAs($admin)
        ->withoutTwoFactorSession()
        ->get('/admin/confirm-2fa')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/confirm-2fa')
            ->where('locale', 'en')
            ->where('direction', 'ltr')
        );
});

test('intended deep admin URL survives the confirm-2fa redirect and lands the user on that destination after confirmation', function (): void {
    $rawSecret = (new Google2FA)->generateSecretKey(16);
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
        'preferred_locale' => 'en',
    ]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($rawSecret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    // 1. Admin navigates to /admin/customers without session confirmation
    $this->actingAs($admin)
        ->withoutTwoFactorSession()
        ->get('/admin/customers')
        ->assertRedirect('/admin/confirm-2fa');

    // 2. Submit valid code
    $validCode = (new Google2FA)->getCurrentOtp($rawSecret);
    $response = $this->post('/admin/confirm-2fa', [
        'code' => $validCode,
    ]);

    // 3. User is redirected to the intended destination (/admin/customers), not default overview
    $response->assertRedirect('/admin/customers');
    expect(session()->has('auth.two_factor_confirmed_at'))->toBeTrue();

    // 4. Destination opens successfully
    $this->get('/admin/customers')->assertOk();
});

test('localized en admin confirm-2fa routes correctly and preserves localized prefix', function (): void {
    $rawSecret = (new Google2FA)->generateSecretKey(16);
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
        'preferred_locale' => 'en',
    ]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($rawSecret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    // 1. Access /en/admin/orders without confirmed session
    $this->actingAs($admin)
        ->withoutTwoFactorSession()
        ->get('/en/admin/orders')
        ->assertRedirect('/en/admin/confirm-2fa');

    // 2. Localized confirm-2fa screen renders
    $this->get('/en/admin/confirm-2fa')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/confirm-2fa')
            ->where('locale', 'en')
            ->where('direction', 'ltr')
        );

    // 3. Submit valid code to /en/admin/confirm-2fa
    $validCode = (new Google2FA)->getCurrentOtp($rawSecret);
    $response = $this->post('/en/admin/confirm-2fa', [
        'code' => $validCode,
    ]);

    // 4. Redirected to intended localized URL
    $response->assertRedirect('/en/admin/orders');
    expect(session()->has('auth.two_factor_confirmed_at'))->toBeTrue();

    $this->get('/en/admin/orders')->assertOk();
});

test('confirm-2fa store endpoint is rate-limited using the two-factor-management throttle', function (): void {
    $rawSecret = (new Google2FA)->generateSecretKey(16);
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
        'preferred_locale' => 'en',
    ]);
    $admin->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($rawSecret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($admin)->withoutTwoFactorSession();

    // Attempt 5 invalid submissions (within the 5 per minute limit)
    for ($i = 0; $i < 5; $i++) {
        $this->post('/admin/confirm-2fa', ['code' => '000000'])
            ->assertSessionHasErrors('code');
    }

    // 6th attempt is throttled (429 Too Many Requests)
    $this->post('/admin/confirm-2fa', ['code' => '000000'])
        ->assertStatus(429);
});
