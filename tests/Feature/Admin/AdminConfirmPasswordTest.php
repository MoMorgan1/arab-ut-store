<?php

use App\Enums\UserRole;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureAdminMfa;
use App\Http\Middleware\EnsureAdminPassword;
use App\Http\Middleware\PrivateNoStore;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('admin without 2fa can reauthenticate password and successfully enable two factor breaking the 423 loop', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
        'preferred_locale' => 'ar',
    ]);

    // 1. Initial state: enabling 2FA without password confirmation returns 423
    $this->actingAs($admin)
        ->postJson(route('two-factor.enable'))
        ->assertStatus(423);

    // 2. Admin navigates to the security confirm-password entry point
    $response = $this->get(route('admin.security.confirm-password'));

    $response->assertRedirect(route('password.confirm'));
    expect(session('url.intended'))->toBe(route('admin.settings', absolute: false));

    // 3. Admin reaches the Fortify confirm password screen
    $confirmScreenResponse = $this->get(route('password.confirm'));
    $confirmScreenResponse->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/confirm-password')
            ->where('authPage', 'confirm_password'),
        );

    // 4. Admin submits their password to confirm
    $confirmResponse = $this->post(route('password.confirm'), [
        'password' => 'SecurePassword!12',
    ]);

    // Fortify redirects to the intended URL (admin settings)
    $confirmResponse->assertRedirect(route('admin.settings', absolute: false));
    expect(session()->has('auth.password_confirmed_at'))->toBeTrue();

    // 5. Following confirmation, enabling two-factor succeeds instead of returning 423
    $this->postJson(route('two-factor.enable'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($admin->fresh()->two_factor_secret)->toBeString();
});

test('localized english admin without 2fa can reauthenticate and returns to english settings', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
        'preferred_locale' => 'en',
    ]);

    $this->actingAs($admin);

    $response = $this->get(route('localized.admin.security.confirm-password'));

    $response->assertRedirect(route('password.confirm'));
    expect(session('url.intended'))->toBe(route('localized.admin.settings', absolute: false));

    $confirmResponse = $this->post(route('password.confirm'), [
        'password' => 'SecurePassword!12',
    ]);

    $confirmResponse->assertRedirect(route('localized.admin.settings', absolute: false));
    expect(session()->has('auth.password_confirmed_at'))->toBeTrue();

    $this->postJson(route('two-factor.enable'))
        ->assertOk();

    expect($admin->fresh()->two_factor_secret)->toBeString();
});

test('admin confirm password entry point is reachable by privileged accounts without 2fa configured', function (
    UserRole $role,
    string $routeName,
    string $expectedIntendedRoute,
) {
    $user = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
    ]);

    expect($user->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $this->actingAs($user)
        ->get(route($routeName))
        ->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toBe(route($expectedIntendedRoute, absolute: false));
})->with([
    'Canonical Admin' => [UserRole::Admin, 'admin.security.confirm-password', 'admin.settings'],
    'Canonical Staff' => [UserRole::Staff, 'admin.security.confirm-password', 'admin.settings'],
    'Localized Admin' => [UserRole::Admin, 'localized.admin.security.confirm-password', 'localized.admin.settings'],
    'Localized Staff' => [UserRole::Staff, 'localized.admin.security.confirm-password', 'localized.admin.settings'],
]);

test('plain customers cannot reach admin confirm password entry point', function (string $path) {
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'password' => 'SecurePassword!12',
    ]);

    $this->actingAs($customer)
        ->get($path)
        ->assertForbidden();
})->with([
    'Canonical' => ['/admin/security/confirm-password'],
    'English' => ['/en/admin/security/confirm-password'],
]);

test('unauthenticated guests are redirected to login', function (string $path, string $login) {
    $this->get($path)->assertRedirect($login);
})->with([
    'Canonical' => ['/admin/security/confirm-password', '/en/login'],
    'English' => ['/en/admin/security/confirm-password', '/en/login'],
]);

test('passwordless privileged user is redirected to set up account password', function (
    string $locale,
    string $path,
    string $destination,
) {
    $staff = User::factory()->create([
        'role' => UserRole::Staff,
        'password' => null,
        'preferred_locale' => $locale,
    ]);

    $this->actingAs($staff)
        ->get($path)
        ->assertRedirect($destination);
})->with([
    'Canonical' => ['en', '/admin/security/confirm-password', '/en/my-account/security'],
    'English' => ['en', '/en/admin/security/confirm-password', '/en/my-account/security'],
]);

test('admin confirm password route has admin protection middleware and excludes ensure admin mfa', function (
    string $routeName,
) {
    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull();

    $middleware = $route?->gatherMiddleware() ?? [];

    expect($middleware)->toContain(
        'auth',
        EnsureActiveUser::class,
        EnsureAdminAccess::class,
        PrivateNoStore::class,
        'inertia.encrypt',
        EnsureAdminPassword::class,
    )->and($middleware)->not->toContain(EnsureAdminMfa::class);
})->with([
    'Canonical' => ['admin.security.confirm-password'],
    'English alias' => ['localized.admin.security.confirm-password'],
]);
