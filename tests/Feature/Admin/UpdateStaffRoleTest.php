<?php

use App\Enums\UserRole;
use App\Models\StaffAuditLog;
use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

test('Admin actor can promote Staff member to Admin', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $staff = createStaffTestActor(UserRole::Staff);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$staff->public_id}/role", [
            'expected_role' => 'staff',
            'role' => 'admin',
        ]);

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'data' => [
                'role' => 'admin',
            ],
        ]);

    expect($staff->fresh()->role)->toBe(UserRole::Admin);

    $audit = StaffAuditLog::query()->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit?->action)->toBe('staff.role_changed')
        ->and($audit?->auditable_id)->toBe($staff->id)
        ->and($audit?->actor_user_id)->toBe($admin->id)
        ->and($audit?->metadata)->toBe([
            'previous_role' => 'staff',
            'new_role' => 'admin',
        ]);
});

test('Admin actor can demote Admin to Staff when multiple active Admins exist', function (): void {
    $admin1 = createStaffTestActor(UserRole::Admin);
    $admin2 = createStaffTestActor(UserRole::Admin);

    $response = $this->actingAs($admin1)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$admin2->public_id}/role", [
            'expected_role' => 'admin',
            'role' => 'staff',
        ]);

    $response->assertOk();
    expect($admin2->fresh()->role)->toBe(UserRole::Staff);
});

test('Admin actor cannot demote the last active Admin account', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $otherAdmin = createStaffTestActor(UserRole::Admin);
    $otherAdmin->forceFill(['is_active' => false])->save();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$admin->public_id}/role", [
            'expected_role' => 'admin',
            'role' => 'staff',
        ])
        ->assertForbidden();
});

test('Admin actor cannot modify their own role', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    createStaffTestActor(UserRole::Admin); // second admin

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$admin->public_id}/role", [
            'expected_role' => 'admin',
            'role' => 'staff',
        ])
        ->assertForbidden();
});

test('Staff actor cannot modify any role', function (): void {
    $staff1 = createStaffTestActor(UserRole::Staff);
    $staff2 = createStaffTestActor(UserRole::Staff);

    $this->actingAs($staff1)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$staff2->public_id}/role", [
            'expected_role' => 'staff',
            'role' => 'admin',
        ])
        ->assertForbidden();
});

test('stale expected_role returns 409 conflict JSON', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $target = createStaffTestActor(UserRole::Staff);

    $response = $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$target->public_id}/role", [
            'expected_role' => 'admin', // Stale! Current role is staff
            'role' => 'admin',
        ]);

    $response->assertStatus(409)
        ->assertJson([
            'member' => (string) $target->public_id,
            'currentRole' => 'staff',
        ]);
});

test('unknown extra payload fields are rejected with validation error', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $staff = createStaffTestActor(UserRole::Staff);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson("/admin/api/team/{$staff->public_id}/role", [
            'expected_role' => 'staff',
            'role' => 'admin',
            'extra_field' => 'malicious',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['unexpected_fields']);
});

test('role update requires password confirmation', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $staff = createStaffTestActor(UserRole::Staff);

    $this->actingAs($admin)
        ->postJson("/admin/api/team/{$staff->public_id}/role", [
            'expected_role' => 'staff',
            'role' => 'admin',
        ])
        ->assertStatus(423);
});

function createStaffTestActor(UserRole $role): User
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
