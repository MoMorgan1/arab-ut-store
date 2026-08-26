<?php

use App\Enums\UserRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

test('Admin with confirmed MFA receives settings page with security and team props', function (): void {
    $admin = createSettingsUser(UserRole::Admin, confirmed: true);
    $staff = createSettingsUser(UserRole::Staff, confirmed: false);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get('/admin/settings');

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/settings')
            ->where('locale', 'ar')
            ->where('direction', 'rtl')
            ->where('auth', null)
            ->where('adminIdentity.name', $admin->name)
            ->where('adminIdentity.role', 'admin')
            ->where('mfa.confirmed', true)
            ->where('team.currentUserId', (string) $admin->public_id)
            ->has('team.members', 2)
            ->where('team.members.0.id', (string) $admin->public_id)
            ->where('team.members.0.role', 'admin')
            ->where('team.members.0.isActive', true)
            ->where('team.members.0.mfaConfirmed', true)
            ->where('team.members.1.id', (string) $staff->public_id)
            ->where('team.members.1.role', 'staff')
            ->where('team.members.1.mfaConfirmed', false)
            ->where('teamUrls.roleUrlTemplate', '/admin/api/team/__ID__/role')
            ->where('teamUrls.statusUrlTemplate', '/admin/api/team/__ID__/status')
            ->has('servicePricing.schedules', 3)
            ->where('servicePricing.schedules.0.serviceType', 'fut_champions')
            ->where('servicePricing.schedules.1.serviceType', 'rivals')
            ->where('servicePricing.schedules.2.serviceType', 'coins')
            ->where('servicePricing.schedules.2.configuration.minimum', 50_000)
            ->has('servicePricing.schedules.2.configuration.tiers', 3)
            ->where('servicePricingUrls.updateUrlTemplate', '/admin/api/settings/service-pricing/__SERVICE__')
            ->where('servicePricingUrls.statusUrlTemplate', '/admin/api/settings/service-pricing/__SERVICE__/status'));

    expect($response->getContent())
        ->not->toContain(
            'two_factor_secret',
            'two_factor_recovery_codes',
            'remember_token',
            (string) $admin->getRawOriginal('password'),
        );
});

test('Staff actor receives security section but null team and servicePricing props', function (): void {
    $staff = createSettingsUser(UserRole::Staff, confirmed: true);

    $response = $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get('/admin/settings');

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/settings')
            ->where('team', null)
            ->where('teamUrls', null)
            ->where('servicePricing', null)
            ->where('servicePricingUrls', null));
});

test('Unconfirmed Staff actor can reach settings page without EnsureAdminMfa blocking', function (): void {
    $staff = createSettingsUser(UserRole::Staff, confirmed: false);

    $response = $this->actingAs($staff)
        ->get('/admin/settings');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/settings')
            ->where('mfa.confirmed', false)
            ->where('team', null));
});

test('settings page requires active account', function (): void {
    $inactiveAdmin = createSettingsUser(UserRole::Admin, confirmed: true);
    $inactiveAdmin->forceFill(['is_active' => false])->save();

    $this->actingAs($inactiveAdmin)
        ->get('/admin/settings')
        ->assertForbidden();

    $activeAdmin = createSettingsUser(UserRole::Admin, confirmed: true);

    $this->actingAs($activeAdmin)
        ->get('/admin/settings')
        ->assertOk();
});

function createSettingsUser(UserRole $role, bool $confirmed, string $locale = 'en'): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
        'preferred_locale' => $locale,
        'is_active' => true,
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
