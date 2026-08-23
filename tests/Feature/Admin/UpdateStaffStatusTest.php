<?php

use App\Enums\UserRole;
use App\Models\StaffAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

test('Admin actor can deactivate an active staff member and terminates their sessions', function (): void {
    $admin = createStatusTestActor(UserRole::Admin);
    $staff = createStatusTestActor(UserRole::Staff);

    DB::table('sessions')->insert([
        'id' => 'test-session-id',
        'user_id' => $staff->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla/5.0',
        'payload' => 'payload-data',
        'last_activity' => now()->timestamp,
    ]);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$staff->public_id}/status", [
            'action' => 'deactivate',
            'expected_active' => true,
        ]);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'data' => [
                'isActive' => false,
            ],
        ]);

    expect($staff->fresh()->is_active)->toBeFalse();
    expect(DB::table('sessions')->where('user_id', $staff->id)->count())->toBe(0);

    $audit = StaffAuditLog::query()->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit?->action)->toBe('staff.deactivated')
        ->and($audit?->auditable_id)->toBe($staff->id)
        ->and($audit?->actor_user_id)->toBe($admin->id)
        ->and($audit?->metadata)->toBe([
            'previous_active' => true,
            'new_active' => false,
        ]);
});

test('Admin actor can reactivate an inactive staff member', function (): void {
    $admin = createStatusTestActor(UserRole::Admin);
    $staff = createStatusTestActor(UserRole::Staff);
    $staff->forceFill(['is_active' => false])->save();

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$staff->public_id}/status", [
            'action' => 'activate',
            'expected_active' => false,
        ]);

    $response->assertOk()
        ->assertJson([
            'data' => [
                'isActive' => true,
            ],
        ]);

    expect($staff->fresh()->is_active)->toBeTrue();

    $audit = StaffAuditLog::query()->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit?->action)->toBe('staff.reactivated');
});

test('Admin actor cannot deactivate their own account', function (): void {
    $admin = createStatusTestActor(UserRole::Admin);
    createStatusTestActor(UserRole::Admin); // second admin

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$admin->public_id}/status", [
            'action' => 'deactivate',
            'expected_active' => true,
        ])
        ->assertForbidden();
});

test('Admin actor cannot deactivate the last active Admin account', function (): void {
    $admin = createStatusTestActor(UserRole::Admin);
    $otherAdmin = createStatusTestActor(UserRole::Admin);
    $otherAdmin->forceFill(['is_active' => false])->save();

    // Trying to deactivate $admin (which is the last active admin) via an attempt
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$admin->public_id}/status", [
            'action' => 'deactivate',
            'expected_active' => true,
        ])
        ->assertForbidden();
});

test('Staff actor cannot modify staff status', function (): void {
    $staff1 = createStatusTestActor(UserRole::Staff);
    $staff2 = createStatusTestActor(UserRole::Staff);

    $this->actingAs($staff1)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$staff2->public_id}/status", [
            'action' => 'deactivate',
            'expected_active' => true,
        ])
        ->assertForbidden();
});

test('stale expected_active returns 409 conflict JSON', function (): void {
    $admin = createStatusTestActor(UserRole::Admin);
    $staff = createStatusTestActor(UserRole::Staff);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$staff->public_id}/status", [
            'action' => 'deactivate',
            'expected_active' => false, // Stale! Staff is currently active (true)
        ]);

    $response->assertStatus(409)
        ->assertJson([
            'member' => (string) $staff->public_id,
            'isActive' => true,
        ]);
});

test('unknown extra payload fields are rejected with validation error', function (): void {
    $admin = createStatusTestActor(UserRole::Admin);
    $staff = createStatusTestActor(UserRole::Staff);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$staff->public_id}/status", [
            'action' => 'deactivate',
            'expected_active' => true,
            'unexpected_field' => 123,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['unexpected_fields']);
});

test('status update requires password confirmation', function (): void {
    $admin = createStatusTestActor(UserRole::Admin);
    $staff = createStatusTestActor(UserRole::Staff);

    $this->actingAs($admin)
        ->postJson("/admin/api/team/{$staff->public_id}/status", [
            'action' => 'deactivate',
            'expected_active' => true,
        ])
        ->assertStatus(423);
});

function createStatusTestActor(UserRole $role): User
{
    $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
    $user = User::factory()->create([
        'role' => $role,
        'password' => 'SecurePassword!12',
        'is_active' => true,
    ]);
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}
