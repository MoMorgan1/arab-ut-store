<?php

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\ServicePriceSchedule;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

beforeEach(function (): void {
    ServicePriceSchedule::query()->firstOrCreate(
        ['service_type' => ServiceType::Rivals],
        [
            'public_id' => (string) Str::ulid(),
            'version' => 1,
            'configuration' => ['steps' => ['7:6' => 11000]],
            'is_active' => true,
        ],
    );
});

function createStatusTestAdmin(): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create([
        'role' => UserRole::Admin,
        'password' => 'SecurePassword!12',
        'is_active' => true,
    ]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

test('coins is rejected at the controller: it has a schedule but cannot be switched off', function (): void {
    // The controller used to accept coins and let the Action reject it with a
    // different error, so a client saw two shapes for one mistake.
    $admin = createStatusTestAdmin();
    $before = ServicePriceSchedule::query()->where('service_type', ServiceType::Coins)->value('is_active');

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/coins/status', [
            'action' => 'deactivate',
            'expected_active' => true,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['service_type']);

    expect(ServicePriceSchedule::query()->where('service_type', ServiceType::Coins)->value('is_active'))->toBe($before);
});

test('a manual service can be deactivated through the same route', function (): void {
    $admin = createStatusTestAdmin();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/settings/service-pricing/rivals/status', [
            'action' => 'deactivate',
            'expected_active' => true,
        ])
        ->assertOk();

    expect((bool) ServicePriceSchedule::query()->where('service_type', ServiceType::Rivals)->value('is_active'))->toBeFalse();
});
