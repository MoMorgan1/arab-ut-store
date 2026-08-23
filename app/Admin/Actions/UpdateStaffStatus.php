<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Exceptions\AdminStaffStatusConflict;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateStaffStatus
{
    public function __construct(
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    public function execute(
        User $actor,
        string $staffPublicId,
        string $action,
        bool $expectedActive,
        ?string $ipAddress = null,
    ): User {
        if (! $actor->is_active || ! $actor->can(AdminPermission::StaffManage->value)) {
            throw new AuthorizationException('This action requires staff.manage permission.');
        }

        if ($actor->role !== UserRole::Admin) {
            throw new AuthorizationException('Only Admin actors may update staff status.');
        }

        return DB::transaction(function () use (
            $actor,
            $staffPublicId,
            $action,
            $expectedActive,
            $ipAddress,
        ): User {
            /** @var User $target */
            $target = User::query()
                ->where('public_id', $staffPublicId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($target->role, [UserRole::Admin, UserRole::Staff], true)) {
                throw new AuthorizationException('Only Admin or Staff accounts can have their status updated.');
            }

            if ($action === 'deactivate' && $actor->id === $target->id) {
                throw new AuthorizationException('You cannot deactivate your own account.');
            }

            if ((bool) $target->is_active !== $expectedActive) {
                throw new AdminStaffStatusConflict((string) $target->public_id, (bool) $target->is_active);
            }

            $newActive = match ($action) {
                'deactivate' => false,
                'activate' => true,
                default => throw new InvalidArgumentException("Invalid action: {$action}"),
            };

            if ($action === 'deactivate' && $target->role === UserRole::Admin && $target->is_active) {
                $activeAdminCount = User::query()
                    ->where('role', UserRole::Admin)
                    ->where('is_active', true)
                    ->where('id', '!=', $target->id)
                    ->count();

                if ($activeAdminCount === 0) {
                    throw new AuthorizationException('Cannot deactivate the last active Admin account.');
                }
            }

            $previousActive = (bool) $target->is_active;
            $target->is_active = $newActive;
            $target->save();

            if ($action === 'deactivate') {
                DB::table('sessions')->where('user_id', $target->id)->delete();
            }

            $auditAction = $action === 'deactivate' ? 'staff.deactivated' : 'staff.reactivated';

            $this->recordStaffAudit->execute(
                $actor,
                $target,
                new StaffAuditEvent(
                    action: $auditAction,
                    metadata: [
                        'previous_active' => $previousActive,
                        'new_active' => $newActive,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $target;
        });
    }
}
