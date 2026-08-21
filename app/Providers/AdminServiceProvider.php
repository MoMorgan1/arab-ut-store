<?php

namespace App\Providers;

use App\Admin\Authorization\AdminAccess;
use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AdminServiceProvider extends ServiceProvider
{
    public function boot(AdminAccess $access): void
    {
        foreach (AdminPermission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user): bool => $access->allows($user, $permission),
            );
        }
    }
}
