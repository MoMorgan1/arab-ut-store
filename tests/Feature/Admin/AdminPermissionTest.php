<?php

use App\Admin\Authorization\AdminAccess;
use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('the central matrix preserves every approved role permission decision', function (
    UserRole $role,
    string $ability,
    bool $expected,
): void {
    $user = User::factory()->create(['role' => $role]);

    expect(app(AdminAccess::class)->allows($user, AdminPermission::from($ability)))
        ->toBe($expected)
        ->and(Gate::forUser($user)->allows($ability))->toBe($expected);
})->with(adminPermissionMatrix());

test('inactive privileged accounts cannot retain an approved admin permission', function (
    UserRole $role,
    string $ability,
): void {
    $user = User::factory()->create([
        'role' => $role,
        'is_active' => false,
    ]);

    expect(app(AdminAccess::class)->allows($user, AdminPermission::from($ability)))
        ->toBeFalse()
        ->and(Gate::forUser($user)->allows($ability))->toBeFalse();
})->with(inactivePrivilegedPermissions());

/** @return array<string, array{UserRole, string, bool}> */
function adminPermissionMatrix(): array
{
    $staffAbilities = [
        'dashboard.view',
        'orders.view',
        'orders.update',
        'orders.cancel',
        'orders.refund',
        'order_credentials.view',
        'customers.view',
        'customers.update_status',
        'payments.view',
        'payments.refund',
        'wallet.view',
        'wallet.adjust',
        'catalog.view',
    ];
    $allAbilities = [
        ...$staffAbilities,
        'catalog.manage',
        'audit.view',
        'staff.view',
        'staff.manage',
        'settings.view',
        'settings.manage',
    ];
    $matrix = [];

    foreach ($allAbilities as $ability) {
        $matrix["admin {$ability}"] = [UserRole::Admin, $ability, true];
        $matrix["staff {$ability}"] = [UserRole::Staff, $ability, in_array($ability, $staffAbilities, true)];
        $matrix["customer {$ability}"] = [UserRole::Customer, $ability, false];
        $matrix["service account {$ability}"] = [UserRole::ServiceAccount, $ability, false];
    }

    return $matrix;
}

/** @return array<string, array{UserRole, string}> */
function inactivePrivilegedPermissions(): array
{
    $permissions = [];

    $abilities = array_unique(array_map(
        fn (array $row): string => $row[1],
        array_values(adminPermissionMatrix()),
    ));

    foreach ($abilities as $ability) {
        $permissions["inactive admin {$ability}"] = [UserRole::Admin, $ability];
        $permissions["inactive staff {$ability}"] = [UserRole::Staff, $ability];
    }

    return $permissions;
}
