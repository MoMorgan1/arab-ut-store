<?php

use App\Enums\UserRole;
use App\Models\StaffAuditLog;
use App\Models\User;

test('an Admin can grant Staff access to an existing customer account', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'email' => 'future.staff@example.test',
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/team/grants', [
            'email' => 'FUTURE.STAFF@example.test',
            'role' => 'staff',
        ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['data' => ['role' => 'staff']]);

    expect($customer->fresh()->role)->toBe(UserRole::Staff);

    $audit = StaffAuditLog::query()->latest('id')->first();
    expect($audit?->action)->toBe('staff.role_changed')
        ->and($audit?->metadata['previous_role'])->toBe('customer')
        ->and($audit?->metadata['new_role'])->toBe('staff')
        ->and($audit?->metadata['source'])->toBe('admin_ui');
});

test('granting never creates an account or touches a password', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $countBefore = User::query()->count();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/team/grants', [
            'email' => 'nobody@example.test',
            'role' => 'admin',
        ])
        ->assertStatus(422)
        ->assertJson(['reason' => 'no_such_account']);

    expect(User::query()->count())->toBe($countBefore);
});

test('a service account cannot be promoted and is not distinguishable from a missing one', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $service = User::factory()->create([
        'role' => UserRole::ServiceAccount,
        'email' => 'automation@example.test',
    ]);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/team/grants', [
            'email' => 'automation@example.test',
            'role' => 'admin',
        ])
        ->assertStatus(422)
        ->assertJson(['reason' => 'no_such_account']);

    expect($service->fresh()->role)->toBe(UserRole::ServiceAccount);
});

test('an Admin cannot grant a role to themselves', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/team/grants', [
            'email' => (string) $admin->email,
            'role' => 'staff',
        ])
        ->assertStatus(422)
        ->assertJson(['reason' => 'self']);

    expect($admin->fresh()->role)->toBe(UserRole::Admin);
});

test('granting a role the account already holds is refused', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $staff = createStaffTestActor(UserRole::Staff);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/team/grants', [
            'email' => (string) $staff->email,
            'role' => 'staff',
        ])
        ->assertStatus(422)
        ->assertJson(['reason' => 'already_granted']);
});

test('a deactivated account must be reactivated before it can be promoted', function (): void {
    $admin = createStaffTestActor(UserRole::Admin);
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'email' => 'suspended@example.test',
        'is_active' => false,
    ]);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/team/grants', [
            'email' => 'suspended@example.test',
            'role' => 'staff',
        ])
        ->assertStatus(422)
        ->assertJson(['reason' => 'inactive_account']);

    expect($customer->fresh()->role)->toBe(UserRole::Customer);
});

test('Staff actors and guests cannot grant access', function (): void {
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'email' => 'target@example.test',
    ]);

    $this->postJson('/admin/api/team/grants', [
        'email' => 'target@example.test',
        'role' => 'staff',
    ])->assertUnauthorized();

    $staff = createStaffTestActor(UserRole::Staff);

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/team/grants', [
            'email' => 'target@example.test',
            'role' => 'staff',
        ])
        ->assertForbidden();

    expect($customer->fresh()->role)->toBe(UserRole::Customer);
});

test('unknown fields and bad roles are rejected', function (array $payload, string $errorKey): void {
    $admin = createStaffTestActor(UserRole::Admin);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/admin/api/team/grants', array_merge([
            'email' => 'someone@example.test',
            'role' => 'staff',
        ], $payload))
        ->assertStatus(422)
        ->assertJsonValidationErrors($errorKey);
})->with([
    'unknown field' => [['is_superuser' => true], 'unexpected_fields'],
    'invalid role' => [['role' => 'owner'], 'role'],
    'missing email' => [['email' => ''], 'email'],
    'malformed email' => [['email' => 'not-an-email'], 'email'],
]);
