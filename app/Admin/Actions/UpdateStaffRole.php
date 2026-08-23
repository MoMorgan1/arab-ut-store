<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Exceptions\AdminStaffRoleConflict;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateStaffRole
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(
        User $actor,
        string $staffPublicId,
        string $newRole,
        string $expectedRole,
        ?string $ipAddress = null,
    ): User {
        if (! $actor->is_active || ! $actor->can(AdminPermission::StaffManage->value)) {
            throw new AuthorizationException('This action requires staff.manage permission.');
        }

        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only Admin actors may update staff roles.');
        }

        $targetRole = match ($newRole) {
            'admin' => UserRole::Admin,
            'staff' => UserRole::Staff,
            default => throw new InvalidArgumentException("Invalid role: {$newRole}"),
        };

        return DB::transaction(function () use (
            $actor,
            $staffPublicId,
            $targetRole,
            $expectedRole,
            $ipAddress,
        ): User {
            /** @var User $target */
            $target = User::query()
                ->where('public_id', $staffPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($target->role, [UserRole::Admin, UserRole::Staff], true)) {
                throw new AuthorizationException('Only Admin or Staff accounts can have their role updated.');
            }

            if ($actor->id === $target->id) {
                throw new AuthorizationException('You cannot modify your own staff role.');
            }

            if ($target->role->value !== $expectedRole) {
                throw new AdminStaffRoleConflict((string) $target->public_id, $target->role->value);
            }

            if ($target->role === UserRole::Admin && $target->is_active && $targetRole === UserRole::Staff) {
                $activeAdminCount = User::query()
                    ->where('role', UserRole::Admin)
                    ->where('is_active', true)
                    ->where('id', '!=', $target->id)
                    ->count();

                if ($activeAdminCount === 0) {
                    throw new AuthorizationException('Cannot demote the last active Admin account.');
                }
            }

            $previousRole = $target->role;
            $target->role = $targetRole;
            $target->save();

            $this->recordStaffAudit->execute(
                $actor,
                $target,
                new StaffAuditEvent(
                    action: 'staff.role_changed',
                    metadata: [
                        'previous_role' => $previousRole->value,
                        'new_role' => $targetRole->value,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $target;
        });
    }
}
