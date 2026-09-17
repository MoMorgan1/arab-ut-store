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
        AdminPermission::OrdersCreate,
        AdminPermission::OrdersUpdate,
        AdminPermission::OrdersCancel,
        AdminPermission::OrderCredentialsView,
        // Owner decision, 2026-09-17: Staff work the fulfillment queue, so
        // they see it. They do not get `FulfillmentViewCost` (our margin) or
        // `FulfillmentAct` (spending money at a supplier).
        AdminPermission::FulfillmentView,
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
