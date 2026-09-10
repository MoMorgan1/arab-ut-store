<?php

use App\Admin\Authorization\AdminAccess;
use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Models\User;

// The Admin Actions no longer compare the role themselves; this pins the
// invariant they now rely on: none of their permissions is granted to Staff.
test('staff never hold the permissions the admin actions require', function (AdminPermission $permission): void {
    $staff = new User;
    $staff->role = UserRole::Staff;
    $staff->is_active = true;

    expect((new AdminAccess)->allows($staff, $permission))->toBeFalse();
})->with([
    'staff.manage' => AdminPermission::StaffManage,
    'catalog.manage' => AdminPermission::CatalogManage,
    'customers.update_contact' => AdminPermission::CustomersUpdateContact,
    'customers.update_status' => AdminPermission::CustomersUpdateStatus,
    'settings.manage' => AdminPermission::SettingsManage,
    'orders.refund' => AdminPermission::OrdersRefund,
]);
