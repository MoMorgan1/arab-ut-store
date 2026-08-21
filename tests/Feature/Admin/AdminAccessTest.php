<?php

use App\Enums\UserRole;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureAdminMfa;
use App\Http\Middleware\EnsureAdminPassword;
use App\Http\Middleware\EnsureEligibleTwoFactorChallenge;
use App\Http\Middleware\PrivateNoStore;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;

beforeEach(function (): void {
    $middleware = [
        'auth',
        EnsureActiveUser::class,
        EnsureAdminAccess::class,
        PrivateNoStore::class,
        'inertia.encrypt',
        EnsureAdminMfa::class,
    ];

    Route::get('/__tests/admin-admission', fn () => response('admitted'))
        ->middleware($middleware)
        ->name('tests.admin.admission');
    Route::get('/en/__tests/admin-admission', fn () => response('admitted'))
        ->defaults('locale', 'en')
        ->middleware($middleware)
        ->name('tests.localized.admin.admission');

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
});

test('guests cannot enter the Admin MFA enrollment route', function (string $path, string $login): void {
    $this->get($path)->assertRedirect($login);
})->with([
    'Arabic' => ['/admin/security/mfa', '/login'],
    'English' => ['/en/admin/security/mfa', '/en/login'],
]);

test('nonprivileged accounts receive a forbidden Admin response', function (UserRole $role): void {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get('/admin/security/mfa')
        ->assertForbidden();
})->with([
    'Customer' => [UserRole::Customer],
    'Service account' => [UserRole::ServiceAccount],
]);

test('inactive Staff cannot retain Admin route admission', function (): void {
    $staff = User::factory()->create([
        'role' => UserRole::Staff,
        'is_active' => false,
    ]);

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get('/admin/security/mfa')
        ->assertForbidden();
});

test('passwordless Staff are sent to the localized account security setup', function (
    string $locale,
    string $path,
    string $destination,
): void {
    $staff = User::factory()->create([
        'role' => UserRole::Staff,
        'password' => null,
        'preferred_locale' => $locale,
    ]);

    $this->actingAs($staff)->get($path)->assertRedirect($destination);
})->with([
    'Arabic' => ['ar', '/admin/security/mfa', '/my-account/security'],
    'English' => ['en', '/en/admin/security/mfa', '/en/my-account/security'],
]);

test('ordinary Admin routes send unconfirmed Staff through localized MFA enrollment', function (
    string $locale,
    string $path,
    string $destination,
): void {
    $staff = privilegedUser(UserRole::Staff, confirmed: false, locale: $locale);

    $this->actingAs($staff)
        ->get($path)
        ->assertRedirect($destination);
})->with([
    'Arabic' => ['ar', '/__tests/admin-admission', '/admin/security/mfa'],
    'English' => ['en', '/en/__tests/admin-admission', '/en/admin/security/mfa'],
]);

test('confirmed Admin and Staff can enter ordinary private Admin responses', function (UserRole $role): void {
    $user = privilegedUser($role, confirmed: true);

    $this->actingAs($user)
        ->get('/__tests/admin-admission')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSeeText('admitted');
})->with([
    'Admin' => [UserRole::Admin],
    'Staff' => [UserRole::Staff],
]);

test('Admin enrollment routes retain the complete localized admission middleware', function (
    string $routeName,
): void {
    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(
            'auth',
            EnsureActiveUser::class,
            EnsureAdminAccess::class,
            PrivateNoStore::class,
            'inertia.encrypt',
            EnsureAdminPassword::class,
            'password.confirm',
        );
})->with([
    'Arabic' => ['admin.security.mfa'],
    'English' => ['localized.admin.security.mfa'],
]);

test('every Fortify MFA management route is private and independently throttled', function (string $routeName): void {
    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(
            EnsureEligibleTwoFactorChallenge::class,
            PrivateNoStore::class,
            'throttle:two-factor-management',
        );
})->with([
    'enable' => ['two-factor.enable'],
    'confirm' => ['two-factor.confirm'],
    'disable' => ['two-factor.disable'],
    'QR code' => ['two-factor.qr-code'],
    'secret key' => ['two-factor.secret-key'],
    'recovery codes' => ['two-factor.recovery-codes'],
    'regenerate recovery codes' => ['two-factor.regenerate-recovery-codes'],
]);

test('Fortify challenge routes preserve the eligibility boundary and challenge throttle', function (string $routeName): void {
    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(EnsureEligibleTwoFactorChallenge::class);

    if ($routeName === 'two-factor.login.store') {
        expect($route?->gatherMiddleware())->toContain('throttle:two-factor');
    }
})->with([
    'challenge page' => ['two-factor.login'],
    'challenge submission' => ['two-factor.login.store'],
]);

function privilegedUser(UserRole $role, bool $confirmed, string $locale = 'ar'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
        'preferred_locale' => $locale,
    ]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINTESTTOTPSECRET'),
        'two_factor_confirmed_at' => $confirmed ? now() : null,
    ])->save();

    return $user;
}
