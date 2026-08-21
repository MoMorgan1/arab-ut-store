<?php

namespace App\Admin\Authorization;

use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Models\User;

final class AdminAccess
{
    /** @var list<AdminPermission> */
    private const STAFF = [
        AdminPermission::DashboardView,
        AdminPermission::OrdersView,
        AdminPermission::OrdersUpdate,
        AdminPermission::OrdersCancel,
        AdminPermission::OrderCredentialsView,
    ];

    public function allows(User $user, AdminPermission $permission): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Staff => in_array($permission, self::STAFF, true),
            UserRole::Customer, UserRole::ServiceAccount => false,
        };
    }
}
