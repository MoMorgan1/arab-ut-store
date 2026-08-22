<?php

use App\Enums\UserRole;
use App\Models\StaffAuditLog;
use App\Models\User;

test('an operator can promote an existing customer to Admin and the change is audited', function (): void {
    $user = User::factory()->create(['email' => 'owner@example.test']);

    $this->artisan('admin:grant-role', ['email' => 'Owner@Example.test'])
        ->expectsOutputToContain('owner@example.test is now admin.')
        ->assertSuccessful();

    expect($user->fresh()->role)->toBe(UserRole::Admin);

    $log = StaffAuditLog::query()->where('action', 'staff.role_changed')->sole();

    expect($log->auditable_id)->toBe($user->id)
        ->and($log->actor_user_id)->toBeNull()
        ->and($log->metadata)->toBe([
            'previous_role' => 'customer',
            'new_role' => 'admin',
            'source' => 'console',
        ]);
});

test('staff can be granted and revoked', function (): void {
    $user = User::factory()->create();

    $this->artisan('admin:grant-role', ['email' => $user->email, '--role' => 'staff'])->assertSuccessful();
    expect($user->fresh()->role)->toBe(UserRole::Staff);

    $this->artisan('admin:grant-role', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
    expect($user->fresh()->role)->toBe(UserRole::Customer);
});

test('invalid roles, unknown emails, and service accounts are refused', function (): void {
    $this->artisan('admin:grant-role', ['email' => 'nobody@example.test', '--role' => 'owner'])
        ->assertExitCode(2);

    $this->artisan('admin:grant-role', ['email' => 'nobody@example.test'])
        ->assertFailed();

    $service = User::factory()->create(['role' => UserRole::ServiceAccount]);

    $this->artisan('admin:grant-role', ['email' => $service->email])->assertFailed();
    expect($service->fresh()->role)->toBe(UserRole::ServiceAccount);
    expect(StaffAuditLog::query()->count())->toBe(0);
});
